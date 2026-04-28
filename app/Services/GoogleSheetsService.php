<?php

namespace Modules\FinTech\Services;

use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Exception as GoogleException;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\ClearValuesRequest;
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

  /**
  * Setup service untuk user tertentu (dengan token OAuth user).
  */
  public function setupForUser($user): void
  {
    $this->client = $this->createUserClient($user);
    $this->service = new GoogleSheets($this->client);
    $this->driveService = new GoogleDrive($this->client);
  }

  /**
  * Buat GoogleClient dengan token user.
  */
  protected function createUserClient($user): GoogleClient
  {
    $client = new GoogleClient();
    $client->setClientId(config('fintech.google.oauth_client_id'));
    $client->setClientSecret(config('fintech.google.oauth_client_secret'));
    $client->setRedirectUri(config('fintech.google.oauth_redirect_uri'));
    $client->addScope([GoogleSheets::SPREADSHEETS, GoogleDrive::DRIVE_FILE]);
    $client->setAccessType('offline');

    $setting = UserSetting::where('user_id', $user->id)->first();
    if ($setting && $setting->google_access_token) {
      $token = [
        'access_token' => $setting->google_access_token,
        'refresh_token' => $setting->google_refresh_token,
        'expires_in' => 3599,
      ];
      $client->setAccessToken($token);

      // Refresh token jika expired
      if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
          $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
          if (!isset($newToken['error'])) {
            $this->saveToken($setting, $newToken);
            $client->setAccessToken($newToken);
          }
        }
      }
    }

    return $client;
  }

  /**
  * Simpan token baru ke user_settings.
  */
  protected function saveToken(UserSetting $setting, array $token): void
  {
    $setting->google_access_token = $token['access_token'];
    if (isset($token['refresh_token'])) {
      $setting->google_refresh_token = $token['refresh_token'];
    }
    if (isset($token['expires_in'])) {
      $setting->google_token_expires_at = now()->addSeconds($token['expires_in']);
    }
    $setting->save();
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
        $this->service->spreadsheets->get($spreadsheetId);
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
  * Membuat spreadsheet baru dengan 3 sheet.
  */
  public function createSpreadsheetForUser($user): string
  {
    try {
      $title = "FinTech - " . ($user->name ?? "User {$user->id}");

      $spreadsheet = new Spreadsheet([
        'properties' => ['title' => $title],
      ]);

      $spreadsheet = $this->service->spreadsheets->create($spreadsheet);
      $spreadsheetId = $spreadsheet->spreadsheetId;

      // Rename sheet pertama
      $sheets = $spreadsheet->getSheets();
      if (count($sheets) > 0) {
        $sheetId = $sheets[0]->getProperties()->getSheetId();
        $this->renameSheet($spreadsheetId, $sheetId, self::SHEET_TRANSACTIONS);
      }

      // Buat sheet Transfer dan Budget
      $this->addSheetIfNotExists($spreadsheetId, self::SHEET_TRANSFERS);
      $this->addSheetIfNotExists($spreadsheetId, self::SHEET_BUDGETS);

      Log::info("Spreadsheet dibuat untuk user {$user->id}", [
        'spreadsheet_id' => $spreadsheetId,
      ]);

      return $spreadsheetId;
    } catch(GoogleException $e) {
      Log::error("Google Error:", [
        'message' => $e->getMessage(),
        'trace' => $e->getTrace()
      ]);
      throw $e;
    } catch (\Exception $e) {
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

    if ($clear) {
      $this->service->spreadsheets_values->clear(
        $spreadsheetId,
        $sheetName,
        new ClearValuesRequest()
      );
    }

    $this->service->spreadsheets_values->update(
      $spreadsheetId,
      $sheetName . '!A1',
      new ValueRange(['values' => [$headers]]),
      ['valueInputOption' => 'RAW']
    );

    if (!empty($values)) {
      $this->service->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A2',
        new ValueRange(['values' => $values]),
        ['valueInputOption' => 'RAW']
      );
    }

    $this->autoResizeColumns($spreadsheetId, $sheetName, count($headers));
  }

  /**
  * URL spreadsheet.
  */
  public function getSpreadsheetUrl(string $spreadsheetId): string
  {
    return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}";
  }

  // ----- Helper methods -----

  protected function addSheetIfNotExists(string $spreadsheetId, string $sheetName): void
  {
    $spreadsheet = $this->service->spreadsheets->get($spreadsheetId);
    $existingNames = array_map(
      fn($s) => $s->getProperties()->getTitle(),
      $spreadsheet->getSheets()
    );

    if (!in_array($sheetName, $existingNames)) {
      $requests = [new SheetsRequest([
        'addSheet' => ['properties' => ['title' => $sheetName]],
      ])];
      $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
    }
  }

  protected function renameSheet(string $spreadsheetId, int $sheetId, string $newName): void
  {
    $requests = [new SheetsRequest([
      'updateSheetProperties' => [
        'properties' => ['sheetId' => $sheetId, 'title' => $newName],
        'fields' => 'title',
      ],
    ])];
    $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
  }

  protected function autoResizeColumns(string $spreadsheetId, string $sheetName, int $columnCount): void
  {
    $sheetId = $this->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null) return;

    $requests = [new SheetsRequest([
      'autoResizeDimensions' => [
        'dimensions' => [
          'sheetId' => $sheetId,
          'dimension' => 'COLUMNS',
          'startIndex' => 0,
          'endIndex' => $columnCount,
        ],
      ],
    ])];
    $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
  }

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
}