<?php

namespace Modules\FinTech\Services\Google;

use Modules\FinTech\Exports\ChartDataProcessor;
use Modules\FinTech\Services\Google\GoogleSheetsClient;
use Modules\FinTech\Services\Google\SheetWriter;
use Modules\FinTech\Services\Google\SpreadsheetManager;

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

    // 1. Bersihkan sheet
    $this->spreadsheetManager->rebuildSheetIfExists($spreadsheetId, $sheetName);
    if ($clear) {
      $this->writer->clearSheet($spreadsheetId, $sheetName);
    }

    // 2. Mulai batch
    $this->writer->beginBatch($spreadsheetId, $sheetName);

    $headers = array_keys($data[0]);
    $values = array_map(fn($row) => array_values($row), $data);
    $colCount = count($headers);

    $cursor = new SheetCursor();

    // --- Judul & Metadata ---
    $title = $this->getTitle($dataType);
    $this->writer->writeTitle($spreadsheetId, $sheetName, $title, $cursor, $colCount);
    if ($metadata) {
      $this->writer->writeMetadata($spreadsheetId, $sheetName, $metadata, $cursor, $colCount);
    }

    // --- Tabel Utama ---
    $tableStartRow = $cursor->row;
    $this->writer->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);
    $headerEndRow = $cursor->row - 1;
    $dataStartRow = $cursor->row;
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $cursor);

    if ($dataType === 'transactions') {
      $this->writer->applyCurrencyFormat($spreadsheetId, $sheetName, $dataStartRow, $dataEndRow, $summary);
      $this->writer->applyTransactionColors($spreadsheetId, $sheetName, $values, $dataStartRow, $dataEndRow);
    }

    $this->writer->applyBordersToRange($spreadsheetId, $sheetName, $tableStartRow, $dataEndRow, 0, $colCount, $headers);
    $this->writer->applyBasicFilter($spreadsheetId, $sheetName, $tableStartRow, $headerEndRow, 0, $colCount);

    // --- Ringkasan ---
    $summaryEndRow = $dataEndRow;
    $summaryInfo = [];
    if ($dataType === 'transactions' && $rawTransactions) {
      $cursor->advanceRow();
      $summaryInfo = $this->writer->writeSummaryWithStats(
        $spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary
      );
      $summaryEndRow = $summaryInfo ? $summaryInfo['endRow'] : $dataEndRow;
    }

    // --- Blok Kanan (Top Tabel, Kategori) ---
    $rightColIndex = $colCount + 1;
    $cursor->setCol($rightColIndex);
    $cursor->row = $tableStartRow;

    $includeTop = $summary['include_top5'] ?? false;
    $nextColIndex = $rightColIndex;

    if ($dataType === 'transactions' && $rawTransactions && $includeTop) {
      $this->writer->writeTopSpendingToSheet($spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary);
      $cursor->advanceRow();
      $this->writer->writeTopIncomeToSheet($spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary);
      $cursor->advanceRow();
      $nextColIndex = $rightColIndex + 5;
    }

    $inclueCategoryExpense = $summary['include_category_expense'] ?? false;
    $categoryTableInfo = null;
    if ($dataType === 'transactions' && $rawTransactions && $inclueCategoryExpense) {
      $categoryTableInfo = $this->writer->writeCategoryExpenseTable(
        $spreadsheetId, $sheetName, $rawTransactions, $cursor, $summary
      );
      if ($categoryTableInfo) {
        $cursor->advanceRow();
      }
    }

    // --- Kirim semua batch (styling, border, warna, filter) ---
    $this->writer->commit($spreadsheetId);

    // --- Chart (langsung) ---
    $includeChart = ($dataType === 'transactions' && ($summary['include_chart'] ?? false) && !empty($rawTransactions));
    $chartEndRow = 0;
    if ($includeChart) {
      $cursor->setCol($nextColIndex);
      $cursor->row = $tableStartRow;
      $chartRow = $cursor->row;

      if (!empty($summaryInfo)) {
        $this->writer->writeTransactionChart(
          $spreadsheetId, $sheetName,
          $summaryInfo['dataStartRow'],
          $summaryInfo['dataEndRow'],
          $chartRow,
          $cursor->col,
          $summaryInfo['dataStartCol'],
          $summaryInfo['dataStartCol'] + 1,
          $summaryInfo['dataStartCol'] + 2
        );
      } else {
        $this->writer->writeTransactionChart(
          $spreadsheetId, $sheetName,
          $dataStartRow, $dataEndRow,
          $chartRow, $cursor->col
        );
      }

      if (!empty($categoryTableInfo)) {
        $pieChartRow = $chartRow + 13 + 2;
        $this->writer->writeCategoryPieChart(
          $spreadsheetId, $sheetName,
          $categoryTableInfo['dataStartRow'],
          $categoryTableInfo['dataEndRow'],
          $categoryTableInfo['startCol'],
          $categoryTableInfo['startCol'] + 1,
          $pieChartRow,
          $cursor->col
        );
        $chartEndRow = $pieChartRow + 15;
      } else {
        $chartEndRow = $chartRow + 20;
      }
    }

    // --- Footer ---
    $lastAddRow = ($dataType === 'transactions') ? $cursor->row : 0;
    $finalRow = max($summaryEndRow, $chartEndRow, $lastAddRow);
    $cursor->setCol(0);
    $cursor->row = $finalRow + 2;

    $this->writer->beginBatch($spreadsheetId, $sheetName); // batch untuk footer
    $this->writer->writeFooter($spreadsheetId, $sheetName, $cursor, $headers);
    $this->writer->commit($spreadsheetId);

    // --- Auto‑resize ---
    $maxCol = max($colCount, $nextColIndex + 5);
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