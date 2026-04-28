<?php

namespace Modules\FinTech\Services\Google;

use Modules\FinTech\Services\Google\GoogleSheetsClient;
use Modules\FinTech\Exports\Google\SpreadsheetManager;
use Modules\FinTech\Exports\Google\SheetWriter;
use Modules\FinTech\Exports\Google\SheetStyler;

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
    ?string $dataType = null
  ): void
  {
    if (empty($data)) return;

    $this->spreadsheetManager->addSheetIfNotExists($spreadsheetId, $sheetName);

    if ($clear) {
      $this->writer->clearSheet($spreadsheetId, $sheetName);
    }

    $headers = array_keys($data[0]);
    $values = array_map(fn($row) => array_values($row), $data);
    $currentRow = 1;
    $metaRows = 0;

    // metadata
    if ($metadata) {
      $this->writer->writeMetadata($spreadsheetId, $sheetName, $metadata, $currentRow);
    }

    // header
    $headerStartRow = $currentRow;
    $this->writer->writeHeaders($spreadsheetId, $sheetName, $headers, $currentRow, $dataType);
    $headerRows = ($dataType === 'transactions') ? 2 : 1;
    $dataStartRow = $headerStartRow + $headerRows;

    // data
    $dataEndRow = $this->writer->writeData($spreadsheetId, $sheetName, $values, $currentRow);

    // subtotal
    $subStartRow = $currentRow + 1; // jarak 1 baris
    $currentRow = $subStartRow;
    if ($summary) {
      $this->writer->writeSubtotal($spreadsheetId, $sheetName, $summary, $dataType, $currentRow, $headers);
    }
    $subEndRow = $currentRow - 1;

    // footer
    $footerRow = $currentRow + 1;
    $this->writer->writeFooter($spreadsheetId, $sheetName, $footerRow, $headers);

    // styling
    $this->styler->apply(
      $spreadsheetId, $sheetName,
      $headerStartRow, $dataType, $headers, $values,
      $dataStartRow, $dataEndRow,
      $subStartRow, $subEndRow,
      $footerRow
    );

    // auto‑resize
    $this->styler->autoResizeColumns($spreadsheetId, $sheetName, count($headers));
  }

  /**
  * URL spreadsheet.
  */
  public function getSpreadsheetUrl(string $spreadsheetId): string
  {
    return $this->spreadsheetManager->getSpreadsheetUrl($spreadsheetId);
  }
}