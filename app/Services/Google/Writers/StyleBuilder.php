<?php

namespace Modules\FinTech\Services\Google\Writers;

use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Modules\FinTech\Services\Google\GoogleSheetsClient;

class StyleBuilder
{
  protected array $requests = [];

  public function addRequest(SheetsRequest $request): void
  {
    $this->requests[] = $request;
  }

  public function commit(GoogleSheetsClient $client, string $spreadsheetId): void
  {
    if (empty($this->requests)) return;

    $batch = new BatchUpdateSpreadsheetRequest(['requests' => $this->requests]);
    $client->executeWithBackoff(function () use ($client, $spreadsheetId, $batch) {
      return $client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    });
    $this->requests = [];
  }

  public function reset(): void
  {
    $this->requests = [];
  }
}