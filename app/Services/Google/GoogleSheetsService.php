<?php

namespace Modules\FinTech\Services\Google;

use Modules\FinTech\Exports\ChartDataProcessor;

class GoogleSheetsService
{
  protected GoogleSheetsClient $client;
  protected SpreadsheetManager $spreadsheetManager;
  protected SheetWriter $writer;

  public function __construct(
    GoogleSheetsClient $client,
    SpreadsheetManager $spreadsheetManager,
    SheetWriter $writer
  ) {
    $this->client = $client;
    $this->spreadsheetManager = $spreadsheetManager;
    $this->writer = $writer;
  }

  public function setupForUser($user): void
  {
    $this->client->setupForUser($user);
  }

  public function getOrCreateSpreadsheet($user): string
  {
    return $this->spreadsheetManager->getOrCreateSpreadsheet($user);
  }

  public function exportDataToSheet(
    string $spreadsheetId,
    string $sheetName,
    array $data,
    bool $clear = true,
    ?array $metadata = null,
    ?array $summary = null,
    ?string $dataType = null,
    ?array $rawTransactions = null
  ): void {
    if (empty($data)) return;

    $this->spreadsheetManager->addSheetIfNotExists($spreadsheetId, $sheetName);

    if ($clear) {
      $this->writer->clearSheet($spreadsheetId, $sheetName);
    }

    $headers = array_keys($data[0]);
    $values = array_map(fn($row) => array_values($row), $data);
    $colCount = count($headers);

    $cursor = new SheetCursor();

    // 1. Metadata
    if ($metadata) {
      $this->writer->writeMetadata($spreadsheetId, $sheetName, $metadata, $cursor, $colCount);
    }

    // 2. Header tabel utama
    $tableStartRow = $cursor->row;
    $this->writer->writeHeaders($spreadsheetId, $sheetName, $headers, $cursor, $dataType);
    $headerEndRow = $cursor->row - 1;

    // 3. Data utama
    $dataStartRow = $cursor->row;
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);

    // 3b. Warna & border data utama
    if ($dataType === 'transactions') {
      $this->writer->applyTransactionColors($spreadsheetId, $sheetName, $values, $dataStartRow, $dataEndRow);
    }
    $this->writer->applyBordersToRange($spreadsheetId, $sheetName, $tableStartRow, $dataEndRow, 0, $colCount, $headers);

    // Filter (kecuali transaksi karena merge vertikal)
    if ($dataType !== 'transactions') {
      $this->writer->applyBasicFilter($spreadsheetId, $sheetName, $tableStartRow, $headerEndRow, 0, $colCount);
    }

    // 4. Subtotal
    $cursor->advanceRow();
    $subStartRow = $cursor->row;
    if ($summary) {
      $this->writer->writeSubtotal($spreadsheetId, $sheetName, $summary, $dataType, $cursor, $headers);
      if ($dataType === 'transactions') {
        $this->writer->applySubtotalColors($spreadsheetId, $sheetName, $subStartRow, $cursor->row - 1, $summary);
      }
    }

    // ---- TABEL TAMBAHAN DI SEBELAH KANAN ----
    $rightColIndex = $colCount + 1; // 1 kolom kosong
    $cursor->setCol($rightColIndex);
    $cursor->row = $tableStartRow; // sejajar header

    $includeMonthly = $summary['include_monthly_summary'] ?? false;
    $includeTop = $summary['include_top_spending'] ?? false;

    if ($dataType === 'transactions' && $rawTransactions && ($includeMonthly || $includeTop)) {
      if ($includeMonthly) {
        $this->writeMonthlySummaryToSheet($spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary);
        $cursor->advanceRow(); // jarak 1 baris sebelum top spending
      }
      if ($includeTop) {
        $this->writeTopSpendingToSheet($spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary);
        $cursor->advanceRow();
      }
    }

    // Kembalikan kolom ke A agar footer ditulis dengan benar
    $cursor->setCol(0);
    // Sisakan 1 baris kosong setelah blok tambahan (atau setelah subtotal jika tidak ada blok tambahan)
    $cursor->advanceRow();

    // 5. Footer
    $this->writer->writeFooter($spreadsheetId, $sheetName, $cursor, $headers);

    // 6. Chart (opsional)
    if ($dataType === 'transactions' && !empty($values)) {
      $cursor->advanceRow(2);
      $chartRow = $cursor->row;
      $this->writer->writeTransactionChart($spreadsheetId, $sheetName, $dataStartRow, $dataEndRow, $chartRow);
    }

    // 7. Auto-resize (mencakup semua kolom yang mungkin digunakan)
    $maxColCount = $rightColIndex + 4; // 4 kolom untuk tabel tambahan
    $this->writer->autoResizeColumns($spreadsheetId, $sheetName, $maxColCount);
  }

  public function getSpreadsheetUrl(string $spreadsheetId): string
  {
    return $this->spreadsheetManager->getSpreadsheetUrl($spreadsheetId);
  }

  private function writeMonthlySummaryToSheet(
    string $spreadsheetId,
    string $sheetName,
    array $transactions,
    SheetCursor $cursor,
    array $summary
  ): void {
    $grouped = [];
    foreach ($transactions as $row) {
      $date = \DateTime::createFromFormat('d/m/Y', $row['Tanggal'] ?? '');
      if (!$date) continue;
      $key = $date->format('Y-m');
      if (!isset($grouped[$key])) {
        $grouped[$key] = ['income' => 0,
          'expense' => 0,
          'label' => $date->format('M Y')];
      }
      $grouped[$key]['income'] += ChartDataProcessor::parseCurrency($row['Pemasukan'] ?? '0');
      $grouped[$key]['expense'] += ChartDataProcessor::parseCurrency($row['Pengeluaran'] ?? '0');
    }
    ksort($grouped);
    if (empty($grouped)) return;

    $headers = ['Bulan',
      'Pemasukan',
      'Pengeluaran',
      'Net'];
    $values = [];
    $totalIncome = $totalExpense = 0;

    foreach ($grouped as $item) {
      $net = $item['income'] - $item['expense'];
      $totalIncome += $item['income'];
      $totalExpense += $item['expense'];
      $values[] = [
        $item['label'],
        ChartDataProcessor::formatCurrency($item['income'], $summary),
        ChartDataProcessor::formatCurrency($item['expense'], $summary),
        ChartDataProcessor::formatCurrency($net, $summary),
      ];
    }
    $values[] = [
      'Total',
      ChartDataProcessor::formatCurrency($totalIncome, $summary),
      ChartDataProcessor::formatCurrency($totalExpense, $summary),
      ChartDataProcessor::formatCurrency($totalIncome - $totalExpense, $summary),
    ];

    $startCol = $cursor->col;
    $summaryHeaderRow = $cursor->row;
    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);
    $this->writer->applySummaryColors($spreadsheetId, $sheetName, $summaryHeaderRow, $values, $startCol);
    $this->writer->applyBordersToRange($spreadsheetId, $sheetName, $summaryHeaderRow, $dataEndRow, $startCol, count($headers), $headers);
  }

  private function writeTopSpendingToSheet(
    string $spreadsheetId,
    string $sheetName,
    array $transactions,
    SheetCursor $cursor,
    array $summary
  ): void {
    $expenses = array_filter($transactions, fn($r) => ($r['Tipe'] ?? '') === 'Pengeluaran');
    if (empty($expenses)) return;

    usort($expenses, fn($a, $b) =>
      ChartDataProcessor::parseCurrency($b['Pengeluaran'] ?? '0') <=>
      ChartDataProcessor::parseCurrency($a['Pengeluaran'] ?? '0')
    );
    $top5 = array_slice($expenses, 0, 5);

    $headers = ['Tanggal',
      'Kategori',
      'Jumlah',
      'Deskripsi'];
    $values = [];
    foreach ($top5 as $item) {
      $values[] = [
        $item['Tanggal'] ?? '',
        $item['Kategori'] ?? '',
        ChartDataProcessor::formatCurrency(
          ChartDataProcessor::parseCurrency($item['Pengeluaran'] ?? '0'),
          $summary
        ),
        $item['Deskripsi'] ?? '-',
      ];
    }

    $startCol = $cursor->col;
    $topHeaderRow = $cursor->row;
    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);
    $this->writer->applyTopSpendingColors($spreadsheetId, $sheetName, $topHeaderRow, $values, $startCol);
    $this->writer->applyBordersToRange($spreadsheetId, $sheetName, $topHeaderRow, $dataEndRow, $startCol, count($headers), $headers);
  }
}