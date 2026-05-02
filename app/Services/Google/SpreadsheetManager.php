<?php

namespace Modules\FinTech\Services\Google;

use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Illuminate\Support\Facades\Log;
use Modules\FinTech\Models\UserSetting;

class SpreadsheetManager
{
  protected GoogleSheetsClient $client;

  const SHEET_TRANSACTIONS = 'Transaksi';
  const SHEET_TRANSFERS = 'Transfer';
  const SHEET_BUDGETS = 'Budget';

  public function __construct(GoogleSheetsClient $client) {
    $this->client = $client;
  }

  /**
  * Dapatkan atau buat spreadsheet untuk user.
  */
  public function getOrCreateSpreadsheet($user): string
  {
    $setting = UserSetting::where('user_id', $user->id)->first();
    $spreadsheetId = $setting->google_spreadsheet_id ?? null;

    if ($spreadsheetId) {
      try {
        $this->client->getSheetsService()->spreadsheets->get($spreadsheetId);
        return $spreadsheetId;
      } catch (\Exception $e) {
        Log::warning("Spreadsheet user {$user->id} tidak valid, dibuat baru.");
        $spreadsheetId = null;
      }
    }

    $spreadsheetId = $this->createSpreadsheetForUser($user);
    $setting->google_spreadsheet_id = $spreadsheetId;
    $setting->save();

    return $spreadsheetId;
  }

  /**
  * Buat spreadsheet baru dengan 3 sheet default dan grid besar.
  */
  protected function createSpreadsheetForUser($user): string
  {
    $title = "FinTech - " . ($user->name ?? $user->first_name ?? "User {$user->id}");

    $spreadsheet = new Spreadsheet(['properties' => ['title' => $title]]);
    $spreadsheet = $this->client->getSheetsService()->spreadsheets->create($spreadsheet);
    $spreadsheetId = $spreadsheet->spreadsheetId;

    // Rename sheet pertama dan perbesar grid
    $sheets = $spreadsheet->getSheets();
    if (count($sheets) > 0) {
      $sheetId = $sheets[0]->getProperties()->getSheetId();
      $requests = [
        new SheetsRequest([
          'updateSheetProperties' => [
            'properties' => [
              'sheetId' => $sheetId,
              'title' => self::SHEET_TRANSACTIONS,
              'gridProperties' => [
                'rowCount' => 100000,
                'columnCount' => 21,
              ],
            ],
            'fields' => 'title,gridProperties(rowCount,columnCount)',
          ],
        ]),
      ];
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }

    // Tambah sheet Transfer & Budget dengan grid besar
    $this->addSheetIfNotExists($spreadsheetId, self::SHEET_TRANSFERS);
    $this->addSheetIfNotExists($spreadsheetId, self::SHEET_BUDGETS);

    Log::info("Spreadsheet dibuat untuk user {$user->id}", ['spreadsheet_id' => $spreadsheetId]);
    return $spreadsheetId;
  }

  /**
  * Tambahkan sheet jika belum ada, dengan grid besar.
  */
  public function addSheetIfNotExists(string $spreadsheetId, string $sheetName): void
  {
    $spreadsheet = $this->client->getSheetsService()->spreadsheets->get($spreadsheetId);
    $existingNames = array_map(fn($s) => $s->getProperties()->getTitle(), $spreadsheet->getSheets());

    if (!in_array($sheetName, $existingNames)) {
      $requests = [
        new SheetsRequest([
          'addSheet' => [
            'properties' => [
              'title' => $sheetName,
              'gridProperties' => [
                'rowCount' => 100000,
                'columnCount' => 21,
              ],
            ],
          ],
        ]),
      ];
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }
  }

  /**
  * Hapus sheet jika ada, lalu buat ulang dengan grid besar.
  */
  public function rebuildSheetIfExists(string $spreadsheetId, string $sheetName): void
  {
    $sheetId = $this->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId !== null) {
      $requests = [
        new SheetsRequest([
          'deleteSheet' => ['sheetId' => $sheetId],
        ]),
      ];
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }

    // Buat ulang dengan grid besar
    $this->addSheetIfNotExists($spreadsheetId, $sheetName);
  }

  /**
  * Dapatkan sheet ID berdasarkan nama.
  */
  public function getSheetIdByName(string $spreadsheetId, string $sheetName): ?int
  {
    $spreadsheet = $this->client->getSheetsService()->spreadsheets->get($spreadsheetId);
    foreach ($spreadsheet->getSheets() as $sheet) {
      if ($sheet->getProperties()->getTitle() === $sheetName) {
        return $sheet->getProperties()->getSheetId();
      }
    }
    return null;
  }

  /**
  * Hapus semua sheet yang namanya diawali dengan prefix tertentu.
  */
  public function removeSheetsByPrefix(string $spreadsheetId, string $prefix): void
  {
    $spreadsheet = $this->client->getSheetsService()->spreadsheets->get($spreadsheetId);
    $requests = [];
    foreach ($spreadsheet->getSheets() as $sheet) {
      $title = $sheet->getProperties()->getTitle();
      if (str_starts_with($title, $prefix)) {
        $requests[] = new SheetsRequest([
          'deleteSheet' => [
            'sheetId' => $sheet->getProperties()->getSheetId(),
          ],
        ]);
      }
    }
    if (!empty($requests)) {
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }
  }

  /**
  * URL spreadsheet.
  */
  public function getSpreadsheetUrl(string $spreadsheetId): string
  {
    return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}";
  }
}