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

    $title = $this->getTitle($dataType);
    $this->writer->writeTitle($spreadsheetId, $sheetName, $title, $cursor, $colCount);

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

    // ... setelah subtotal (kode Anda sampai langkah 6 tetap sama)

    // 7. Tabel tambahan di sebelah kanan (tanpa pindah posisi)
    $rightColIndex = $colCount + 1;
    $cursor->setCol($rightColIndex);
    $cursor->row = $tableStartRow; // sejajar header utama

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

    // 8. CHART (ditempatkan di bawah subtotal, di KIRI)
    $includeChart = ($dataType === 'transactions' && ($summary['include_chart'] ?? false) && !empty($rawTransactions));
    if ($includeChart) {
      // chart diletakkan 2 baris setelah baris terakhir subtotal (bukan finalRow)
      $chartRow = $subStartRow + 4 + 2; // +4 untuk 3 baris detail + 1 spasi, +2 untuk jarak
      $this->writer->writeTransactionChart(
        $spreadsheetId, $sheetName, $dataStartRow, $dataEndRow, $chartRow
      );
    }

    // 9. FOOTER (di paling bawah, setelah chart dan/atau tabel tambahan)
    // Ambil baris maksimum: akhir subtotal, akhir chart, akhir tabel kanan
    $chartEndRow = $includeChart ? $chartRow + 20 : 0; // perkiraan tinggi chart 20 baris
    $lastMainRow = $subStartRow + 4; // setelah subtotal (3 detail + 1 label)
    $lastAddRow = ($dataType === 'transactions') ? $cursor->row : 0;
    $finalRow = max($lastMainRow, $chartEndRow, $lastAddRow);

    $cursor->setCol(0);
    $cursor->row = $finalRow + 2; // 1 baris kosong

    $this->writer->writeFooter($spreadsheetId, $sheetName, $cursor, $headers);

    // 10. Auto-resize
    $maxCol = max($colCount, $rightColIndex + 4);
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
    $title = "Ringkasan Bulanan";
    $this->writer->writeSimpleTitle($spreadsheetId, $sheetName, $title, $cursor);
    $summaryHeaderRow = $cursor->row;
    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);
    $this->writer->applyCurrencyFormat($spreadsheetId, $sheetName, $summaryHeaderRow + 1, $dataEndRow, $summary, $startCol + 1, 3);
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
    $title = "Top 5 Pengeluaran";
    $this->writer->writeSimpleTitle($spreadsheetId, $sheetName, $title, $cursor);
    $topHeaderRow = $cursor->row;
    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);
    $this->writer->applyCurrencyFormat($spreadsheetId, $sheetName, $topHeaderRow + 1, $dataEndRow, $summary, $startCol + 2, 1);
    $this->writer->applyTopSpendingColors($spreadsheetId, $sheetName, $topHeaderRow, $values, $startCol);
    $this->writer->applyBordersToRange(
      $spreadsheetId, $sheetName, $topHeaderRow, $dataEndRow, $startCol, count($headers), $headers
    );
  }

  private function getTitle(string $dataType) {
    return match($dataType) {
      'transactions' => 'Riwayat Transaksi',
      'budgets' => 'Ringkasan Budget',
      'transfer' => 'Riwayat Transfer',
      default => 'Laporan Keuangan Lengkap'
      };
    }
  }