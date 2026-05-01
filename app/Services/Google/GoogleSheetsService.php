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

    // 0. Judul halaman
    $title = $this->getTitle($dataType);
    $this->writer->writeTitle($spreadsheetId, $sheetName, $title, $cursor, $colCount);

    // 1. Metadata
    if ($metadata) {
      $this->writer->writeMetadata($spreadsheetId, $sheetName, $metadata, $cursor, $colCount);
    }

    // 2. Header tabel utama (satu baris, tanpa merge)
    $tableStartRow = $cursor->row;
    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);
    $headerEndRow = $cursor->row - 1; // 1 baris header

    // 3. Data utama (nilai float)
    $dataStartRow = $cursor->row;
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);

    // 4. Format mata uang & warna (khusus transaksi)
    if ($dataType === 'transactions') {
      $this->writer->applyCurrencyFormat($spreadsheetId, $sheetName, $dataStartRow, $dataEndRow, $summary);
      $this->writer->applyTransactionColors(
        $spreadsheetId, $sheetName, $values, $dataStartRow, $dataEndRow
      );
    }

    // 5. Border & filter
    $this->writer->applyBordersToRange(
      $spreadsheetId, $sheetName, $tableStartRow, $dataEndRow, 0, $colCount, $headers
    );
    $this->writer->applyBasicFilter(
      $spreadsheetId, $sheetName, $tableStartRow, $headerEndRow, 0, $colCount
    );

    // 6. Ringkasan Bulanan + Statistik (menggantikan subtotal, di kiri)
    $summaryEndRow = $dataEndRow; // fallback jika tidak ada ringkasan
    if ($dataType === 'transactions' && $rawTransactions) {
      $cursor->advanceRow(); // jarak 1 baris setelah data utama
      $summaryStartRow = $cursor->row;
      $this->writeSummaryWithStats(
        $spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary
      );
      $summaryEndRow = $cursor->row - 1; // baris terakhir setelah ringkasan
    }

    // 7. Tabel Top Spending (tetap di kanan, sejajar header utama)
    $rightColIndex = $colCount + 1; // 1 kolom kosong
    $cursor->setCol($rightColIndex);
    $cursor->row = $tableStartRow; // sejajar header

    $includeTop = $summary['include_top_spending'] ?? false;
    if ($dataType === 'transactions' && $rawTransactions && $includeTop) {
      $this->writeTopSpendingToSheet(
        $spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary
      );
      $cursor->advanceRow();
    }

    // 8. Chart (opsional, di kiri bawah ringkasan)
    $includeChart = ($dataType === 'transactions' && ($summary['include_chart'] ?? false) && !empty($rawTransactions));
    $chartEndRow = 0;
    if ($includeChart) {
      // Letakkan chart 2 baris setelah baris terakhir ringkasan
      $chartRow = $summaryEndRow + 2;
      $this->writer->writeTransactionChart(
        $spreadsheetId, $sheetName, $dataStartRow, $dataEndRow, $chartRow
      );
      $chartEndRow = $chartRow + 20; // perkiraan tinggi chart 20 baris
    }

    // 9. Footer (di paling bawah)
    $lastAddRow = ($dataType === 'transactions') ? $cursor->row : 0;
    $finalRow = max($summaryEndRow, $chartEndRow, $lastAddRow);
    $cursor->setCol(0);
    $cursor->row = $finalRow + 2; // 1 baris kosong

    $this->writer->writeFooter($spreadsheetId, $sheetName, $cursor, $headers);

    // 10. Auto-resize (semua kolom, termasuk kolom kanan)
    $maxCol = max($colCount, $rightColIndex + 4); // +4 untuk tabel top spending
    $this->writer->autoResizeColumns($spreadsheetId, $sheetName, $maxCol);
  }

  public function getSpreadsheetUrl(string $spreadsheetId): string
  {
    return $this->spreadsheetManager->getSpreadsheetUrl($spreadsheetId);
  }

  // ======================= RINGKASAN + STATISTIK =======================
  private function writeSummaryWithStats(
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
        $grouped[$key] = [
          'income' => 0,
          'expense' => 0,
          'label' => $date->format('M Y')
        ];
      }
      $grouped[$key]['income'] += (float)($row['Pemasukan'] ?? 0);
      $grouped[$key]['expense'] += (float)($row['Pengeluaran'] ?? 0);
    }
    ksort($grouped);
    if (empty($grouped)) return;

    $totalIncome = array_sum(array_column($grouped, 'income'));
    $totalExpense = array_sum(array_column($grouped, 'expense'));
    $monthCount = count($grouped);
    $avgIncome = $monthCount > 0 ? $totalIncome / $monthCount : 0;
    $avgExpense = $monthCount > 0 ? $totalExpense / $monthCount : 0;
    $ratio = $totalIncome > 0 ? ($totalExpense / $totalIncome) * 100 : 0;

    $startCol = $cursor->col; // 0 (kolom A)

    // Judul tabel
    $this->writer->writeSimpleTitle(
      $spreadsheetId, $sheetName, 'Ringkasan Bulanan & Statistik', $cursor
    );

    // Header tabel
    $headers = ['Bulan',
      'Pemasukan',
      'Pengeluaran',
      'Net'];
    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);

    // Data per bulan
    $values = [];
    foreach ($grouped as $item) {
      $values[] = [
        $item['label'],
        $item['income'],
        $item['expense'],
        $item['income'] - $item['expense']
      ];
    }
    // Baris Total
    $values[] = [
      'Total',
      $totalIncome,
      $totalExpense,
      $totalIncome - $totalExpense
    ];
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);

    // Format mata uang untuk kolom B, C, D (indeks 1,2,3)
    $this->writer->applyCurrencyFormat(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values), $dataEndRow, $summary,
      $startCol + 1, 3
    );

    // Tulis baris statistik tambahan
    $statsData = [
      ['Rata‑rata Pemasukan/Bulan',
        $avgIncome,
        '',
        ''],
      ['Rata‑rata Pengeluaran/Bulan',
        '',
        $avgExpense,
        ''],
      ['Rasio Pengeluaran (%)',
        '',
        '',
        round($ratio, 1) . '%'],
    ];
    $this->writer->writeData($spreadsheetId, $sheetName, $statsData, $cursor);

    // Format mata uang untuk sel statistik
    $this->writer->applyCurrencyFormat(
      $spreadsheetId, $sheetName,
      $cursor->row - 3, $cursor->row - 3, $summary,
      $startCol + 1, 1
    );
    $this->writer->applyCurrencyFormat(
      $spreadsheetId, $sheetName,
      $cursor->row - 2, $cursor->row - 2, $summary,
      $startCol + 2, 1
    );

    // Warna
    $this->writer->applySummaryColors(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values) - count($statsData) - 1,
      $values, $startCol
    );

    // Border seluruh area
    $this->writer->applyBordersToRange(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values) - count($statsData) - 1,
      $cursor->row - 1,
      $startCol, 4, $headers
    );
  }

  // ======================= TOP SPENDING =======================
  private function writeTopSpendingToSheet(
    string $spreadsheetId,
    string $sheetName,
    array $transactions,
    SheetCursor $cursor,
    array $summary
  ): void {
    $expenses = array_filter($transactions, fn($r) => ($r['Tipe'] ?? '') === 'Pengeluaran');
    if (empty($expenses)) return;

    usort($expenses, fn($a, $b) => ((float)($b['Pengeluaran'] ?? 0)) <=> ((float)($a['Pengeluaran'] ?? 0)));
    $top5 = array_slice($expenses, 0, 5);

    $startCol = $cursor->col;
    $title = "Top 5 Pengeluaran";
    $this->writer->writeSimpleTitle($spreadsheetId, $sheetName, $title, $cursor);

    $headers = ['Tanggal',
      'Kategori',
      'Jumlah',
      'Deskripsi'];
    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);

    $values = [];
    foreach ($top5 as $item) {
      $values[] = [
        $item['Tanggal'] ?? '',
        $item['Kategori'] ?? '',
        (float)($item['Pengeluaran'] ?? 0),
        $item['Deskripsi'] ?? '-'
      ];
    }
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);

    $this->writer->applyCurrencyFormat(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values), $dataEndRow, $summary,
      $startCol + 2, 1
    );
    $this->writer->applyTopSpendingColors(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values) - 1, $values, $startCol
    );
    $this->writer->applyBordersToRange(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values) - 1, $dataEndRow,
      $startCol, count($headers), $headers
    );
  }

  private function getTitle(string $dataType): string
  {
    return match ($dataType) {
      'transactions' => 'Riwayat Transaksi',
      'transfers' => 'Riwayat Transfer',
      'budgets' => 'Ringkasan Budget',
      default => 'Laporan Keuangan Lengkap'
      };
    }
  }