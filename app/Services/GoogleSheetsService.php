<?php

namespace Modules\FinTech\Services;

use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Drive\Permission as DrivePermission;
use Modules\FinTech\Models\UserSetting;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
  const SHEET_TRANSACTIONS = 'Transaksi';
  const SHEET_TRANSFERS = 'Transfer';
  const SHEET_BUDGETS = 'Budget';

  protected GoogleClient $client;
  protected GoogleSheets $service;
  protected GoogleDrive $driveService;

  public function __construct() {
    $this->client = $this->createClient();
    $this->service = new GoogleSheets($this->client);
    $this->driveService = new GoogleDrive($this->client);
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
    $client->addScope([
      GoogleSheets::SPREADSHEETS,
      GoogleDrive::DRIVE_FILE,
    ]);
    $client->setAccessType('offline');

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
        Log::warning("Spreadsheet user {$user->id} tidak valid, dibuat baru.", [
          'error' => $e->getMessage()
        ]);
        $spreadsheetId = null;
      }
    }

    // Buat baru
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

      // Berikan akses ke service account (meskipun sebenarnya sudah owner)
      $this->grantAccessToSpreadsheet($spreadsheetId);

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
      Log::error("Gagal membuat spreadsheet: " . $e->getMessage(), [
        'user_id' => $user->id,
        'trace' => $e->getTraceAsString()
      ]);
      throw $e;
    }
  }

  /**
  * Memberikan akses ke service account ke spreadsheet.
  */
  public function grantAccessToSpreadsheet(string $spreadsheetId): void
  {
    try {
      // Dapatkan email service account dari file kredensial
      $credentials = json_decode(
        file_get_contents(config('google.service_account_credentials_json')),
        true
      );
      $serviceAccountEmail = $credentials['client_email'] ?? null;

      if (!$serviceAccountEmail) {
        return;
      }

      // Cek apakah permission sudah ada
      $permissions = $this->driveService->permissions->listPermissions($spreadsheetId);
      foreach ($permissions->getPermissions() as $permission) {
        if ($permission->getEmailAddress() === $serviceAccountEmail) {
          return; // Sudah ada akses
        }
      }

      // Tambahkan permission
      $permission = new DrivePermission([
        'type' => 'user',
        'role' => 'writer',
        'emailAddress' => $serviceAccountEmail,
      ]);

      $this->driveService->permissions->create($spreadsheetId, $permission);

      Log::info("Akses diberikan ke service account", [
        'spreadsheet_id' => $spreadsheetId,
        'email' => $serviceAccountEmail,
      ]);
    } catch (\Exception $e) {
      Log::warning("Gagal memberikan akses: " . $e->getMessage());
      // Tidak throw error karena spreadsheet masih bisa diakses oleh pembuatnya
    }
  }

  /**
  * Menulis data ke sheet tertentu.
  */
  public function exportDataToSheet(string $spreadsheetId, string $sheetName, array $data, bool $clear = true): void
  {
    if (empty($data)) {
      return;
    }

    $this->addSheetIfNotExists($spreadsheetId, $sheetName);

    $headers = array_keys($data[0]);
    $values = array_map(fn($row) => array_values($row), $data);

    // Clear dulu jika diminta
    if ($clear) {
      $this->service->spreadsheets_values->clear(
        $spreadsheetId,
        $sheetName,
        new \Google\Service\Sheets\ClearValuesRequest()
      );
    }

    // Tulis header
    $this->service->spreadsheets_values->update(
      $spreadsheetId,
      $sheetName . '!A1',
      new \Google\Service\Sheets\ValueRange(['values' => [$headers]]),
      ['valueInputOption' => 'RAW']
    );

    // Tulis data (append setelah header)
    if (!empty($values)) {
      $this->service->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A2',
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