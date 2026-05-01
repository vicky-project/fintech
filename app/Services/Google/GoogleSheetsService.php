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
      $this->writer->writeSummaryWithStats(
        $spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary
      );
      $summaryEndRow = $cursor->row - 1; // baris terakhir setelah ringkasan
    }

    // 7. Tabel Top (kanan atas)
    $rightColIndex = $colCount + 1; // 1 kolom kosong setelah tabel utama
    $cursor->setCol($rightColIndex);
    $cursor->row = $tableStartRow; // sejajar header utama

    $includeTop = $summary['include_top5'] ?? false;
    $nextColIndex = $rightColIndex; // default: chart di kolom setelah tabel utama

    if ($dataType === 'transactions' && $rawTransactions && $includeTop) {
      $this->writer->writeTopSpendingToSheet(
        $spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary
      );
      $cursor->advanceRow();
      $this->writer->writeTopIncomeToSheet(
        $spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary
      );
      $cursor->advanceRow();
      // Jika tabel Top ada, chart akan ditempatkan di kanan tabel Top
      $nextColIndex = $rightColIndex + 5; // 4 kolom tabel + 1 jarak
    }

    $categoryTableInfo = null;
    if ($dataType === 'transactions' && $rawTransactions) {
      $categoryTableInfo = $this->writer->writeCategoryExpenseTable($spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary);
      if ($categoryTableInfo) {
        $cursor->advanceRow();
      }
    }

    // 8. Chart (di kanan, sejajar header utama)
    $includeChart = ($dataType === 'transactions' && ($summary['include_chart'] ?? false) && !empty($rawTransactions));
    $chartEndRow = 0;
    if ($includeChart) {
      $cursor->setCol($nextColIndex);
      $cursor->row = $tableStartRow;
      $chartRow = $cursor->row;
      $this->writer->writeTransactionChart(
        $spreadsheetId, $sheetName, $dataStartRow, $dataEndRow, $chartRow,
        $cursor->col
      );

      $pieChartRow = $chartRow + 20 + 2;
      if ($categoryTableInfo) {} else {}
      $chartEndRow = $chartRow;
      // Trend chart di bawah chart pertama (kolom yang sama)
      //$trendChartRow = $chartRow + 20 + 2;
      //$this->writer->writeTrendChart(
      //  $spreadsheetId, $sheetName, $dataStartRow, $dataEndRow, $trendChartRow,$cursor->col
      // );
    }

    // 9. Footer
    $lastAddRow = ($dataType === 'transactions') ? $cursor->row : 0;
    $finalRow = max($summaryEndRow, $chartEndRow, $lastAddRow);
    $cursor->setCol(0);
    $cursor->row = $finalRow + 2;

    $this->writer->writeFooter($spreadsheetId, $sheetName, $cursor, $headers);

    // 10. Auto-resize (mencakup semua kolom yang mungkin digunakan)
    $maxCol = max($colCount, $nextColIndex + 5); // +5 untuk lebar chart
    $this->writer->autoResizeColumns($spreadsheetId, $sheetName, $maxCol);
  }

  public function getSpreadsheetUrl(string $spreadsheetId): string
  {
    return $this->spreadsheetManager->getSpreadsheetUrl($spreadsheetId);
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