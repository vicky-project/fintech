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

    // 2. Header tabel utama (satu baris, tanpa merge)
    $tableStartRow = $cursor->row;
    $this->writer->writeHeaders($spreadsheetId, $sheetName, $headers, $cursor, $dataType);
    $headerEndRow = $cursor->row - 1; // 1 baris header

    // 3. Data utama (nilai float)
    $dataStartRow = $cursor->row;
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);

    // 4. Format mata uang untuk kolom E & F (khusus transaksi)
    if ($dataType === 'transactions') {
      $this->writer->applyCurrencyFormat($spreadsheetId, $sheetName, $dataStartRow, $dataEndRow, $summary);
      $this->writer->applyTransactionColors(
        $spreadsheetId, $sheetName, $values, $dataStartRow, $dataEndRow
      );
    }

    // 5. Border tabel utama
    $this->writer->applyBordersToRange(
      $spreadsheetId, $sheetName, $tableStartRow, $dataEndRow, 0, $colCount, $headers
    );

    $this->writer->applyBasicFilter(
      $spreadsheetId, $sheetName, $tableStartRow, $headerEndRow, 0, $colCount
    );

    // 6. Subtotal
    $cursor->advanceRow();
    $subStartRow = $cursor->row;
    if ($summary) {
      $this->writer->writeSubtotal($spreadsheetId, $sheetName, $summary, $dataType, $cursor, $headers);
      if ($dataType === 'transactions') {
        $this->writer->applySubtotalColors(
          $spreadsheetId, $sheetName, $subStartRow, $cursor->row - 1, $summary
        );
      }
    }

    // 7. Tabel tambahan di sebelah kanan
    $rightColIndex = $colCount + 1; // 1 kolom kosong
    $cursor->setCol($rightColIndex);
    $cursor->row = $tableStartRow; // sejajar header

    $includeMonthly = $summary['include_monthly_summary'] ?? false;
    $includeTop = $summary['include_top_spending'] ?? false;

    if ($dataType === 'transactions' && $rawTransactions && ($includeMonthly || $includeTop)) {
      if ($includeMonthly) {
        $this->writeMonthlySummaryToSheet(
          $spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary
        );
        $cursor->advanceRow(); // jarak 1 baris
      }
      if ($includeTop) {
        $this->writeTopSpendingToSheet(
          $spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary
        );
        $cursor->advanceRow();
      }
    }

    // Kembalikan kursor ke kolom A untuk footer
    $cursor->setCol(0);
    // Tentukan baris akhir (mana yang lebih besar: subtotal atau tabel tambahan)
    $lastMainRow = $subStartRow + ($summary ? 4 : 0); // perkiraan baris setelah subtotal
    $lastAddRow = ($dataType === 'transactions') ? $cursor->row : 0;
    $finalRow = max($lastMainRow, $lastAddRow);
    $cursor->row = $finalRow + 1; // 1 baris kosong

    // 8. Footer
    $this->writer->writeFooter($spreadsheetId, $sheetName, $cursor, $headers);

    // 9. Chart (sederhana, langsung dari kolom E/F)
    $includeChart = ($dataType === 'transactions' && ($summary['include_chart'] ?? false) && !empty($rawTransactions));
    if ($includeChart) {
      $cursor->advanceRow(2);
      $chartRow = $cursor->row;
      $this->writer->writeTransactionChart(
        $spreadsheetId, $sheetName, $dataStartRow, $dataEndRow, $chartRow
      );
    }

    // 10. Auto-resize semua kolom
    $maxCol = max($colCount, $rightColIndex + 4); // +4 untuk tabel tambahan
    $this->writer->autoResizeColumns($spreadsheetId, $sheetName, $maxCol);
  }

  public function getSpreadsheetUrl(string $spreadsheetId): string
  {
    return $this->spreadsheetManager->getSpreadsheetUrl($spreadsheetId);
  }

  // ======================== TABEL TAMBAHAN ========================

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
      $grouped[$key]['income'] += (float)($row['Pemasukan'] ?? 0);
      $grouped[$key]['expense'] += (float)($row['Pengeluaran'] ?? 0);
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
        $item['income'],
        $item['expense'],
        $net
      ];
    }
    $values[] = [
      'Total',
      $totalIncome,
      $totalExpense,
      $totalIncome - $totalExpense
    ];

    $startCol = $cursor->col;
    $summaryHeaderRow = $cursor->row;
    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);
    $this->writer->applySummaryColors($spreadsheetId, $sheetName, $summaryHeaderRow, $values, $startCol);
    $this->writer->applyBordersToRange(
      $spreadsheetId, $sheetName, $summaryHeaderRow, $dataEndRow, $startCol, count($headers), $headers
    );
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
      ((float)($b['Pengeluaran'] ?? 0)) <=> ((float)($a['Pengeluaran'] ?? 0))
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
        (float)($item['Pengeluaran'] ?? 0),
        $item['Deskripsi'] ?? '-'
      ];
    }

    $startCol = $cursor->col;
    $topHeaderRow = $cursor->row;
    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);
    $this->writer->applyTopSpendingColors($spreadsheetId, $sheetName, $topHeaderRow, $values, $startCol);
    $this->writer->applyBordersToRange(
      $spreadsheetId, $sheetName, $topHeaderRow, $dataEndRow, $startCol, count($headers), $headers
    );
  }
}