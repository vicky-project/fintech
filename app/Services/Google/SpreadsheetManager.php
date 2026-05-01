<?php

namespace Modules\FinTech\Services\Google;

use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Illuminate\Support\Facades\Log;
use Modules\FinTech\Models\UserSetting;
use Modules\FinTech\Services\Google\GoogleSheetsClient;

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
    $setting = UserSetting::where('user_id', $user->id);
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
  * Buat spreadsheet baru dengan 3 sheet default.
  */
  protected function createSpreadsheetForUser($user): string
  {
    $title = "FinTech - " . ($user->name ?? $user->first_name ?? "User {$user->id}");

    $spreadsheet = new Spreadsheet(['properties' => ['title' => $title]]);
    $spreadsheet = $this->client->getSheetsService()->spreadsheets->create($spreadsheet);
    $spreadsheetId = $spreadsheet->spreadsheetId;

    // Rename sheet pertama
    $sheets = $spreadsheet->getSheets();
    if (count($sheets) > 0) {
      $sheetId = $sheets[0]->getProperties()->getSheetId();
      $this->renameSheet($spreadsheetId, $sheetId, self::SHEET_TRANSACTIONS);
    }

    $this->addSheetIfNotExists($spreadsheetId, self::SHEET_TRANSFERS);
    $this->addSheetIfNotExists($spreadsheetId, self::SHEET_BUDGETS);

    Log::info("Spreadsheet dibuat untuk user {$user->id}", ['spreadsheet_id' => $spreadsheetId]);
    return $spreadsheetId;
  }

  /**
  * Tambahkan sheet jika belum ada.
  */
  public function addSheetIfNotExists(string $spreadsheetId, string $sheetName): void
  {
    $spreadsheet = $this->client->getSheetsService()->spreadsheets->get($spreadsheetId);
    $existingNames = array_map(fn($s) => $s->getProperties()->getTitle(), $spreadsheet->getSheets());

    if (!in_array($sheetName, $existingNames)) {
      $requests = [new SheetsRequest([
        'addSheet' => ['properties' => ['title' => $sheetName]]
      ])];
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }
  }

  /**
  * Hapus sheet jika sudah ada, lalu buat sheet baru dengan nama yang sama.
  * Ini memastikan sheet bersih dari data dan chart lama.
  */
  public function rebuildSheetIfExists(string $spreadsheetId, string $sheetName): void
  {
    $sheetId = $this->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId !== null) {
      // Hapus sheet yang ada
      $requests = [
        new SheetsRequest([
          'deleteSheet' => [
            'sheetId' => $sheetId,
          ]
        ])
      ];
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }

    // Buat sheet baru dengan nama yang sama
    $this->addSheetIfNotExists($spreadsheetId, $sheetName);
  }

  /**
  * Rename sheet berdasarkan ID.
  */
  public function renameSheet(string $spreadsheetId, int $sheetId, string $newName): void
  {
    $requests = [new SheetsRequest([
      'updateSheetProperties' => [
        'properties' => ['sheetId' => $sheetId, 'title' => $newName],
        'fields' => 'title',
      ]
    ])];
    $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
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
  * URL spreadsheet.
  */
  public function getSpreadsheetUrl(string $spreadsheetId): string
  {
    return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}";
  }
}