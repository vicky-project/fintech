<?php

namespace Modules\FinTech\Exports\Google;

use Google\Service\Sheets\Spreadsheet;
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
  * Buat spreadsheet baru dengan 3 sheet default.
  */
  protected function createSpreadsheetForUser($user): string
  {
    $title = "FinTech - " . ($user->name ?? "User {$user->id}");

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
      $requests = [new \Google\Service\Sheets\Request([
        'addSheet' => ['properties' => ['title' => $sheetName]]
      ])];
      $batch = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }
  }

  /**
  * Rename sheet berdasarkan ID.
  */
  public function renameSheet(string $spreadsheetId, int $sheetId, string $newName): void
  {
    $requests = [new \Google\Service\Sheets\Request([
      'updateSheetProperties' => [
        'properties' => ['sheetId' => $sheetId, 'title' => $newName],
        'fields' => 'title',
      ]
    ])];
    $batch = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest(['requests' => $requests]);
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