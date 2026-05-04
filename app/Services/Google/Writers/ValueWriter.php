<?php

namespace Modules\FinTech\Services\Google\Writers;

use Google\Service\Sheets\BatchUpdateValuesRequest;
use Modules\FinTech\Services\Google\GoogleSheetsClient;

class ValueWriter
{
  protected array $queue = [];

  public function queue(string $range, array $values): void
  {
    $this->queue[] = [
      'range' => $range,
      'values' => $values,
      'majorDimension' => 'ROWS',
    ];
  }

  public function commit(GoogleSheetsClient $client, string $spreadsheetId): void
  {
    if (empty($this->queue)) return;

    $body = new BatchUpdateValuesRequest([
      'valueInputOption' => 'RAW',
      'data' => $this->queue,
    ]);

    $client->executeWithBackoff(function () use ($client, $spreadsheetId, $body) {
      return $client->getSheetsService()->spreadsheets_values->batchUpdate($spreadsheetId, $body);
    });

    $this->queue = [];
  }

  public function reset(): void
  {
    $this->queue = [];
  }
}