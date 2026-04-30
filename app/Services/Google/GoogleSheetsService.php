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

    // 2. Header + Filter
    $headerStartRow = $cursor->row;
    $this->writer->writeHeaders($spreadsheetId, $sheetName, $headers, $cursor, $dataType);
    $headerEndRow = $cursor->row - 1;

    // 3. Data
    $dataStartRow = $cursor->row;
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);

    // 3b. Warna & border data utama
    if ($dataType === 'transactions') {
      $this->writer->applyTransactionColors($spreadsheetId, $sheetName, $values, $dataStartRow, $dataEndRow);
    }
    $this->writer->applyBordersToRange($spreadsheetId, $sheetName, $headerStartRow, $dataEndRow, 0, $colCount, $headers);

    // 3c. Filter (setelah data ada)
    if ($dataType === 'transactions') {
      // Filter hanya pada baris kedua header (sub‑header)
      $this->writer->applyBasicFilter($spreadsheetId, $sheetName, $headerEndRow, $headerEndRow, 0, $colCount);
    } else {
      // Tipe lain: filter pada seluruh header
      $this->writer->applyBasicFilter($spreadsheetId, $sheetName, $headerStartRow, $headerEndRow, 0, $colCount);
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
    $subEndRow = $cursor->row - 1;

    // 5. Tabel tambahan
    if ($dataType === 'transactions' && $rawTransactions) {
      $cursor->advanceRow();
      if ($summary['include_monthly_summary'] ?? false) {
        $this->writeMonthlySummaryToSheet($spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary);
        $cursor->advanceRow();
      }
      if ($summary['include_top_spending'] ?? false) {
        $this->writeTopSpendingToSheet($spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary);
        $cursor->advanceRow();
      }
    }

    // 6. Footer
    $cursor->advanceRow();
    $this->writer->writeFooter($spreadsheetId, $sheetName, $cursor, $headers);

    // 7. Chart
    if ($dataType === 'transactions' && !empty($values)) {
      $cursor->advanceRow(2);
      $chartRow = $cursor->row;
      $this->writer->writeTransactionChart($spreadsheetId, $sheetName, $dataStartRow, $dataEndRow, $chartRow);
    }

    // 8. Auto-resize (terakhir)
    $this->writer->autoResizeColumns($spreadsheetId, $sheetName, $colCount);
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
    // Total row
    $values[] = [
      'Total',
      ChartDataProcessor::formatCurrency($totalIncome, $summary),
      ChartDataProcessor::formatCurrency($totalExpense, $summary),
      ChartDataProcessor::formatCurrency($totalIncome - $totalExpense, $summary),
    ];

    $summaryHeaderRow = $cursor->row;
    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);
    $this->writer->applySummaryColors($spreadsheetId, $sheetName, $summaryHeaderRow, $values);
    $this->writer->applyBordersToRange($spreadsheetId, $sheetName, $summaryHeaderRow, $dataEndRow, 0, 4, $headers);
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

    $topHeaderRow = $cursor->row;
    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);
    $this->writer->applyTopSpendingColors($spreadsheetId, $sheetName, $topHeaderRow, $values);
    $this->writer->applyBordersToRange($spreadsheetId, $sheetName, $topHeaderRow, $dataEndRow, 0, 4, $headers);
  }
}