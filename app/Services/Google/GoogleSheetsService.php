<?php

namespace Modules\FinTech\Services\Google;

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

  /**
  * Ekspor data ke sheet tertentu. Data sudah difilter per tahun (atau semua).
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

    // 1. Rebuild sheet
    $this->spreadsheetManager->rebuildSheetIfExists($spreadsheetId, $sheetName);

    // 2. Persiapkan header & values
    $headers = array_keys($data[0]);
    $values = array_map(fn($row) => array_values($row), $data);

    $includeDescription = ($dataType === 'transactions' || $dataType === 'transfers')
    ? ($summary['include_description'] ?? true)
    : true;
    if (!$includeDescription) {
      $headers = array_values(array_diff($headers, ['Deskripsi']));
      $values = array_map(fn($row) => array_slice($row, 0, -1), $values);
    }

    $colCount = count($headers);
    $cursor = new SheetCursor();

    // 3. Mulai batch
    $this->writer->beginBatch($spreadsheetId, $sheetName);

    // Title & Metadata
    $title = $this->getTitle($dataType);
    $this->writer->writeTitle($title, $cursor, $colCount);
    if ($metadata) {
      $this->writer->writeMetadata($metadata, $cursor, $colCount);
    }

    // Header & Data Utama
    $tableStartRow = $cursor->row;
    $this->writer->writeSimpleHeader($headers, $cursor);
    $headerEndRow = $cursor->row - 1;
    $dataStartRow = $cursor->row;
    $dataEndRow = $this->writer->writeData($values, $cursor);

    if ($dataType === 'transactions') {
      $this->writer->applyCurrencyFormat($dataStartRow, $dataEndRow, $summary);
      $this->writer->applyTransactionColors($values, $dataStartRow, $dataEndRow);
    }
    $this->writer->applyBordersToRange($tableStartRow, $dataEndRow, 0, $colCount, $headers);
    $this->writer->applyBasicFilter($tableStartRow, $headerEndRow, 0, $colCount);

    // Ringkasan
    $summaryEndRow = $dataEndRow;
    $summaryInfo = [];
    if ($dataType === 'transactions' && $rawTransactions && ($summary['include_monthly_summary'] ?? false)) {
      $cursor->advanceRow();
      $summaryInfo = $this->writer->writeSummaryWithStats($rawTransactions, $cursor, $summary);
      $summaryEndRow = $summaryInfo ? $summaryInfo['endRow'] : $dataEndRow;
    }

    // Blok Kanan (Top 5, Kategori)
    $rightColIndex = $colCount + 1;
    $cursor->setCol($rightColIndex);
    $cursor->row = $tableStartRow;
    $nextColIndex = $rightColIndex;

    if ($dataType === 'transactions' && $rawTransactions && ($summary['include_top5'] ?? false)) {
      $this->writer->writeTopSpendingToSheet($rawTransactions, $cursor, $summary);
      $cursor->advanceRow();
      $this->writer->writeTopIncomeToSheet($rawTransactions, $cursor, $summary);
      $cursor->advanceRow();
      $nextColIndex = $rightColIndex + 5;
    }

    $categoryTableInfo = null;
    if ($dataType === 'transactions' && $rawTransactions && ($summary['include_category_expense'] ?? false)) {
      $categoryTableInfo = $this->writer->writeCategoryExpenseTable($rawTransactions, $cursor, $summary);
      if ($categoryTableInfo) {
        $cursor->advanceRow();
      }
    }

    // Kirim semua nilai (1 request)
    $this->writer->commitValues();

    // Chart, Footer, Auto‑resize → masuk batch styling
    $includeChart = ($dataType === 'transactions' && ($summary['include_chart'] ?? false) && !empty($rawTransactions));
    $chartEndRow = 0;
    if ($includeChart) {
      $cursor->setCol($nextColIndex);
      $cursor->row = $tableStartRow;
      $chartRow = $cursor->row;

      if (!empty($summaryInfo)) {
        $this->writer->addTransactionChartRequest(
          $summaryInfo['dataStartRow'], $summaryInfo['dataEndRow'],
          $chartRow, $cursor->col,
          $summaryInfo['dataStartCol'],
          $summaryInfo['dataStartCol'] + 1,
          $summaryInfo['dataStartCol'] + 2
        );
      } else {
        $this->writer->addTransactionChartRequest(
          $dataStartRow, $dataEndRow,
          $chartRow, $cursor->col
        );
      }

      if (!empty($categoryTableInfo)) {
        $pieChartRow = $chartRow + 15;
        $this->writer->addCategoryPieChartRequest(
          $categoryTableInfo['dataStartRow'], $categoryTableInfo['dataEndRow'],
          $categoryTableInfo['startCol'], $categoryTableInfo['startCol'] + 1,
          $pieChartRow, $cursor->col
        );
        $chartEndRow = $pieChartRow + 16;
      } else {
        $chartEndRow = $chartRow + 20;
      }
    }

    // Footer + Auto‑resize
    $lastAddRow = ($dataType === 'transactions') ? $cursor->row : 0;
    $finalRow = max($summaryEndRow, $chartEndRow, $lastAddRow);
    $cursor->setCol(0);
    $cursor->row = $finalRow + 2;

    $this->writer->writeFooter($cursor, $headers);
    $this->writer->autoResizeColumns(max($colCount, $nextColIndex + 5));

    // Kirim batch styling (1 request)
    $this->writer->commit();
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