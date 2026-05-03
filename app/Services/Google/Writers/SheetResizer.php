<?php

namespace Modules\FinTech\Services\Google\Writers;

use Google\Service\Sheets\Request as SheetsRequest;

class SheetResizer
{
  public function __construct(protected StyleBuilder $styleBuilder) {}

  public function autoResizeColumns(int $sheetId, int $columnCount): void
  {
    $this->styleBuilder->addRequest(new SheetsRequest([
      'autoResizeDimensions' => [
        'dimensions' => [
          'sheetId' => $sheetId,
          'dimension' => 'COLUMNS',
          'startIndex' => 0,
          'endIndex' => $columnCount,
        ],
      ],
    ]));
  }

  public function hideColumns(string $spreadsheetId, int $sheetId, int $startIndex, int $count, GoogleSheetsClient $client): void
  {
    // Untuk implementasi ini, kita tetap gunakan batch terpisah jika diperlukan.
    $request = new SheetsRequest([
      'updateDimensionProperties' => [
        'range' => [
          'sheetId' => $sheetId,
          'dimension' => 'COLUMNS',
          'startIndex' => $startIndex,
          'endIndex' => $startIndex + $count,
        ],
        'properties' => ['hiddenByUser' => true],
        'fields' => 'hiddenByUser',
      ],
    ]);

    $batch = new BatchUpdateSpreadsheetRequest(['requests' => [$request]]);
    $client->executeWithBackoff(function () use ($spreadsheetId, $batch, $client) {
      return $client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    });
  }
}