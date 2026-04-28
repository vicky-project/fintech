<?php

namespace Modules\FinTech\Services;

use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Modules\FinTech\Models\UserSetting;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
  const SHEET_TRANSACTIONS = 'Transaksi';
  const SHEET_TRANSFERS = 'Transfer';
  const SHEET_BUDGETS = 'Budget';

  protected GoogleClient $client;
  protected GoogleSheets $service;

  public function __construct() {
    $this->client = $this->createClient();
    $this->service = new GoogleSheets($this->client);
  }

  /**
  * Buat Google\Client dari service account JSON.
  */
  protected function createClient(): GoogleClient
  {
    $credentialsPath = config('google.service.file');
    if (!file_exists($credentialsPath)) {
      throw new \Exception("File kredensial Google tidak ditemukan: {$credentialsPath}");
    }

    $client = new GoogleClient();
    $client->setAuthConfig($credentialsPath);
    $client->addScope(GoogleSheets::SPREADSHEETS);
    return $client;
  }

  /**
  * Dapatkan atau buat spreadsheet untuk user.
  */
  public function getOrCreateSpreadsheet($user): string
  {
    $spreadsheetId = $user->userSetting->google_spreadsheet_id ?? null;

    if ($spreadsheetId) {
      try {
        // Cek apakah spreadsheet masih bisa diakses
        $this->service->spreadsheets->get($spreadsheetId);
        return $spreadsheetId;
      } catch (\Exception $e) {
        Log::warning("Spreadsheet user {$user->id} tidak valid, dibuat baru.");
        $spreadsheetId = null;
      }
    }

    $spreadsheetId = $this->createSpreadsheetForUser($user);
    $this->saveSpreadsheetId($user, $spreadsheetId);

    return $spreadsheetId;
  }

  /**
  * Membuat spreadsheet baru dengan 3 sheet.
  */
  public function createSpreadsheetForUser($user): string
  {
    $title = "FinTech - " . ($user->name ?? "User {$user->id}");

    try {
      $spreadsheet = new Spreadsheet([
        'properties' => ['title' => $title],
      ]);

      $spreadsheet = $this->service->spreadsheets->create($spreadsheet);
      $spreadsheetId = $spreadsheet->spreadsheetId;

      // Ganti nama sheet pertama menjadi "Transaksi"
      $sheets = $spreadsheet->getSheets();
      if (count($sheets) > 0) {
        $sheetId = $sheets[0]->getProperties()->getSheetId();
        $this->renameSheet($spreadsheetId, $sheetId, self::SHEET_TRANSACTIONS);
      }

      // Tambahkan sheet Transfer dan Budget
      $this->addSheetIfNotExists($spreadsheetId, self::SHEET_TRANSFERS);
      $this->addSheetIfNotExists($spreadsheetId, self::SHEET_BUDGETS);

      Log::info("Spreadsheet dibuat untuk user {$user->id}", [
        'spreadsheet_id' => $spreadsheetId,
      ]);

      return $spreadsheetId;
    } catch (\Exception $e) {
      Log::error("Gagal membuat spreadsheet: " . $e->getMessage());
      throw $e;
    }
  }

  /**
  * Menulis data ke sheet tertentu.
  */
  public function exportDataToSheet(string $spreadsheetId, string $sheetName, array $data, bool $clear = true): void
  {
    if (empty($data)) return;

    $this->addSheetIfNotExists($spreadsheetId, $sheetName);

    $headers = array_keys($data[0]);
    $values = array_map(fn($row) => array_values($row), $data);

    $range = $sheetName . '!A1';

    // Clear dulu jika diminta
    if ($clear) {
      $this->service->spreadsheets_values->clear($spreadsheetId, $sheetName, new \Google\Service\Sheets\ClearValuesRequest());
    }

    // Tulis header
    $this->service->spreadsheets_values->update(
      $spreadsheetId,
      $range,
      new \Google\Service\Sheets\ValueRange(['values' => [$headers]]),
      ['valueInputOption' => 'RAW']
    );

    // Tulis data (append setelah header)
    if (!empty($values)) {
      $range = $sheetName . '!A2';
      $this->service->spreadsheets_values->update(
        $spreadsheetId,
        $range,
        new \Google\Service\Sheets\ValueRange(['values' => $values]),
        ['valueInputOption' => 'RAW']
      );
    }

    // Auto-resize kolom
    $this->autoResizeColumns($spreadsheetId, $sheetName, count($headers));

    Log::info("Data diekspor ke Google Sheets", [
      'spreadsheet_id' => $spreadsheetId,
      'sheet' => $sheetName,
      'rows' => count($data),
    ]);
  }

  /**
  * URL spreadsheet.
  */
  public function getSpreadsheetUrl(string $spreadsheetId): string
  {
    return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}";
  }

  /**
  * Tambahkan sheet jika belum ada.
  */
  protected function addSheetIfNotExists(string $spreadsheetId, string $sheetName): void
  {
    $spreadsheet = $this->service->spreadsheets->get($spreadsheetId);
    $existingNames = array_map(
      fn($s) => $s->getProperties()->getTitle(),
      $spreadsheet->getSheets()
    );

    if (!in_array($sheetName, $existingNames)) {
      $requests = [
        new SheetsRequest([
          'addSheet' => [
            'properties' => ['title' => $sheetName],
          ],
        ]),
      ];
      $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
    }
  }

  /**
  * Rename sheet berdasarkan ID.
  */
  protected function renameSheet(string $spreadsheetId, int $sheetId, string $newName): void
  {
    $requests = [
      new SheetsRequest([
        'updateSheetProperties' => [
          'properties' => ['sheetId' => $sheetId, 'title' => $newName],
          'fields' => 'title',
        ],
      ]),
    ];
    $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
  }

  /**
  * Auto-resize kolom.
  */
  protected function autoResizeColumns(string $spreadsheetId, string $sheetName, int $columnCount): void
  {
    $sheetId = $this->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null) return;

    $requests = [
      new SheetsRequest([
        'autoResizeDimensions' => [
          'dimensions' => [
            'sheetId' => $sheetId,
            'dimension' => 'COLUMNS',
            'startIndex' => 0,
            'endIndex' => $columnCount,
          ],
        ],
      ]),
    ];
    $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
  }

  /**
  * Ambil sheet ID berdasarkan nama.
  */
  protected function getSheetIdByName(string $spreadsheetId, string $sheetName): ?int
  {
    $spreadsheet = $this->service->spreadsheets->get($spreadsheetId);
    foreach ($spreadsheet->getSheets() as $sheet) {
      if ($sheet->getProperties()->getTitle() === $sheetName) {
        return $sheet->getProperties()->getSheetId();
      }
    }
    return null;
  }

  /**
  * Simpan spreadsheet ID ke user settings.
  */
  protected function saveSpreadsheetId($user, string $spreadsheetId): void
  {
    $setting = UserSetting::firstOrNew(['user_id' => $user->id]);
    $setting->google_spreadsheet_id = $spreadsheetId;
    $setting->save();
  }
}