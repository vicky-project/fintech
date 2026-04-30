<?php

namespace Modules\FinTech\Services\Google;

use Modules\FinTech\Services\Google\GoogleSheetsClient;
use Modules\FinTech\Services\Google\SpreadsheetManager;
use Modules\FinTech\Services\Google\SheetWriter;
use Modules\FinTech\Services\Google\SheetStyler;
use Modules\FinTech\Exports\ChartDataProcessor;

class GoogleSheetsService
{
  protected GoogleSheetsClient $client;
  protected SpreadsheetManager $spreadsheetManager;
  protected SheetWriter $writer;
  protected SheetStyler $styler;

  public function __construct(
    GoogleSheetsClient $client,
    SpreadsheetManager $spreadsheetManager,
    SheetWriter $writer,
    SheetStyler $styler
  ) {
    $this->client = $client;
    $this->spreadsheetManager = $spreadsheetManager;
    $this->writer = $writer;
    $this->styler = $styler;
  }

  /**
  * Setup untuk user tertentu.
  */
  public function setupForUser($user): void
  {
    $this->client->setupForUser($user);
  }

  /**
  * Dapatkan atau buat spreadsheet user.
  */
  public function getOrCreateSpreadsheet($user): string
  {
    return $this->spreadsheetManager->getOrCreateSpreadsheet($user);
  }

  /**
  * Ekspor data ke sheet.
  */
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

    // Inisialisasi kursor
    $cursor = new SheetCursor();

    // Metadata
    if ($metadata) {
      $this->writer->writeMetadata($spreadsheetId, $sheetName, $metadata, $cursor);
    }

    // Header tabel utama
    $headerStartRow = $cursor->row;
    $this->writer->writeHeaders($spreadsheetId, $sheetName, $headers, $cursor, $dataType);
    $headerEndRow = $cursor->row - 1; // baris terakhir header

    // Data
    $dataStartRow = $cursor->row;
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);

    // Subtotal
    $cursor->advanceRow(); // jarak 1 baris kosong
    $subStartRow = $cursor->row;
    if ($summary) {
      $this->writer->writeSubtotal($spreadsheetId, $sheetName, $summary, $dataType, $cursor, $headers);
    }
    $subEndRow = $cursor->row - 1;

    // Tabel tambahan (ringkasan bulanan, top 5 pengeluaran)
    if ($dataType === 'transactions' && $rawTransactions) {
      $cursor->advanceRow(); // jarak 1 baris setelah subtotal
      if ($summary['include_monthly_summary'] ?? false) {
        $this->writeMonthlySummaryToSheet($spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary);
        $cursor->advanceRow(); // jarak setelah tabel
      }
      if ($summary['include_top_spending'] ?? false) {
        $this->writeTopSpendingToSheet($spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary);
        $cursor->advanceRow();
      }
    }

    // Footer
    $cursor->advanceRow(); // jarak 1 baris sebelum footer
    $footerRow = $cursor->row;
    $this->writer->writeFooter($spreadsheetId, $sheetName, $cursor, $headers);

    // Chart (khusus transaksi, jika ada data)
    if ($dataType === 'transactions' && !empty($values)) {
      $cursor->advanceRow(2); // jarak 2 baris setelah footer
      $chartRow = $cursor->row;
      $this->writer->writeTransactionChart(
        $spreadsheetId,
        $sheetName,
        $dataStartRow,
        $dataEndRow,
        $chartRow
      );
    }

    // Styling data utama + footer
    $this->styler->apply(
      $spreadsheetId, $sheetName,
      $headerStartRow, $dataType, $headers, $values,
      $dataStartRow, $dataEndRow,
      $subStartRow, $subEndRow,
      $footerRow
    );

    // Auto-resize semua kolom
    $this->styler->autoResizeColumns($spreadsheetId, $sheetName, count($headers));
  }

  /**
  * URL spreadsheet.
  */
  public function getSpreadsheetUrl(string $spreadsheetId): string
  {
    return $this->spreadsheetManager->getSpreadsheetUrl($spreadsheetId);
  }

  /**
  * Tulis tabel Ringkasan Bulanan di bawah data utama.
  */
  private function writeMonthlySummaryToSheet(string $spreadsheetId, string $sheetName, array $transactions, int &$currentRow, array $summary): void
  {
    // Bangun data ringkasan
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

    // Siapkan data array untuk ditulis
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
    // Baris Total
    $values[] = [
      'Total',
      ChartDataProcessor::formatCurrency($totalIncome, $summary),
      ChartDataProcessor::formatCurrency($totalExpense, $summary),
      ChartDataProcessor::formatCurrency($totalIncome - $totalExpense, $summary),
    ];

    // Tulis ke sheet
    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $currentRow, 'other'); // 'other' untuk header biasa
    $this->writer->writeData($spreadsheetId, $sheetName, $values, $currentRow);
    $currentRow++; // spasi setelah tabel
  }

  /**
  * Tulis tabel Top 5 Pengeluaran di bawah data utama.
  */
  private function writeTopSpendingToSheet(string $spreadsheetId, string $sheetName, array $transactions, int &$currentRow, array $summary): void
  {
    $expenses = array_filter($transactions, fn($r) => ($r['Tipe'] ?? '') === 'Pengeluaran');
    if (empty($expenses)) return;

    usort($expenses, fn($a, $b) =>
      \Modules\FinTech\Exports\ChartDataProcessor::parseCurrency($b['Pengeluaran'] ?? '0') <=>
      \Modules\FinTech\Exports\ChartDataProcessor::parseCurrency($a['Pengeluaran'] ?? '0')
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
        \Modules\FinTech\Exports\ChartDataProcessor::formatCurrency(
          \Modules\FinTech\Exports\ChartDataProcessor::parseCurrency($item['Pengeluaran'] ?? '0'),
          $summary
        ),
        $item['Deskripsi'] ?? '-',
      ];
    }

    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $currentRow, 'other');
    $this->writer->writeData($spreadsheetId, $sheetName, $values, $currentRow);
    $currentRow++;
  }
}