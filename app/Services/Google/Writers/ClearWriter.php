<?php

namespace Modules\FinTech\Services\Google\Writers;

use Google\Service\Sheets\Request as SheetsRequest;
use Google\Service\Sheets\ClearValuesRequest;
use Modules\FinTech\Services\Google\GoogleSheetsClient;

class ClearWriter
{
  public function __construct(
    protected StyleBuilder $styleBuilder,
    protected GoogleSheetsClient $client
  ) {}

  public function clearSheetBatch(int $sheetId): void
  {
    $this->styleBuilder->addRequest(new SheetsRequest([
      'updateCells' => [
        'range' => ['sheetId' => $sheetId],
        'fields' => 'userEnteredValue',
      ],
    ]));
  }

  public function clearSheet(string $spreadsheetId, string $sheetName): void
  {
    $this->client->getSheetsService()->spreadsheets_values->clear(
      $spreadsheetId,
      $sheetName,
      new ClearValuesRequest()
    );
  }
}