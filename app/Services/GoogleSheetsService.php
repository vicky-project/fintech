<?php

namespace Modules\FinTech\Services;

use Revolution\Google\Sheets\Facades\Sheets;
use Modules\FinTech\Models\UserSetting;
use Modules\Telegram\Models\TelegramUser;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
  const SHEET_TRANSACTIONS = 'Transaksi';
  const SHEET_TRANSFERS = 'Transfer';
  const SHEET_BUDGETS = 'Budget';

  /**
  * Dapatkan atau buat spreadsheet untuk user.
  */
  public function getOrCreateSpreadsheet(TelegramUser $user): string
  {

    $spreadsheetId = UserSetting::where('user_id', $user->id)->first()->google_spreadsheet_id ?? null;

    if ($spreadsheetId) {
      try {
        // Cek apakah spreadsheet masih valid
        Sheets::spreadsheet($spreadsheetId)->get();
        return $spreadsheetId;
      } catch (\Exception $e) {
        Log::warning("Spreadsheet user {$user->id} tidak valid, dibuat baru.");
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
    $credentials = config("google.service_account_credential_json");
    \Log::debug('Membaca credentials dari: ' . $credentials);
    \Log::debug("File exists: ".file_exists($credentials) ? 'YES' : 'NO');
    $title = "FinTech - " . ($user->name ?? "User {$user->id}");

    try {
      $newSpreadsheet = Sheets::create($title);
      $spreadsheetId = $newSpreadsheet->spreadsheetId;

      // Rename sheet default menjadi Transaksi
      $sheets = $newSpreadsheet->getSheets();
      if (count($sheets) > 0) {
        $defaultTitle = $sheets[0]->getProperties()->getTitle();
        Sheets::spreadsheet($spreadsheetId)
        ->sheet($defaultTitle)
        ->rename(self::SHEET_TRANSACTIONS);
      }

      // Buat sheet Transfer dan Budget
      $this->addSheetIfNotExists($spreadsheetId, self::SHEET_TRANSFERS);
      $this->addSheetIfNotExists($spreadsheetId, self::SHEET_BUDGETS);

      Log::info("Spreadsheet dibuat untuk user {$user->id}", [
        'spreadsheet_id' => $spreadsheetId
      ]);

      return $spreadsheetId;
    } catch (\Exception $e) {
      Log::error("Gagal membuat spreadsheet: " . $e->getMessage());
      throw $e;
    }
  }

  /**
  * Menulis data ke sheet tertentu.
  *
  * @param string $spreadsheetId
  * @param string $sheetName
  * @param array  $data       Data terformat (array of associative arrays)
  * @param bool   $clear      Hapus isi sheet sebelumnya?
  */
  public function exportDataToSheet(string $spreadsheetId, string $sheetName, array $data, bool $clear = true): void
  {
    if (empty($data)) {
      return;
    }

    $this->addSheetIfNotExists($spreadsheetId, $sheetName);

    // Ambil header dari key baris pertama
    $headers = array_keys($data[0]);
    $values = array_map(fn($row) => array_values($row), $data);

    $sheet = Sheets::spreadsheet($spreadsheetId)->sheet($sheetName);

    if ($clear) {
      $sheet->clear();
    }

    // Tulis header
    $sheet->range('A1')->update([$headers]);

    // Tulis data
    if (!empty($values)) {
      // Gunakan append agar langsung di bawah header
      $sheet->range('A2')->append($values);
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
  * Mendapatkan URL spreadsheet.
  */
  public function getSpreadsheetUrl(string $spreadsheetId): string
  {
    return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}";
  }

  /**
  * Menambahkan sheet jika belum ada.
  */
  protected function addSheetIfNotExists(string $spreadsheetId, string $sheetName): void
  {
    try {
      $spreadsheet = Sheets::spreadsheet($spreadsheetId)->get();
      $existingNames = array_map(
        fn($s) => $s->getProperties()->getTitle(),
        $spreadsheet->getSheets()
      );

      if (!in_array($sheetName, $existingNames)) {
        Sheets::spreadsheet($spreadsheetId)->addSheet($sheetName);
      }
    } catch (\Exception $e) {
      Log::warning("Gagal menambah sheet {$sheetName}: " . $e->getMessage());
    }
  }

  /**
  * Auto-resize kolom menggunakan Google Sheets API.
  */
  protected function autoResizeColumns(string $spreadsheetId, string $sheetName, int $columnCount): void
  {
    try {
      $service = Sheets::getService();
      $sheetId = $this->getSheetIdByName($spreadsheetId, $sheetName);

      if ($sheetId === null) return;

      $requests = [
        new \Google_Service_Sheets_Request([
          'autoResizeDimensions' => [
            'dimensions' => [
              'sheetId' => $sheetId,
              'dimension' => 'COLUMNS',
              'startIndex' => 0,
              'endIndex' => $columnCount,
            ]
          ]
        ])
      ];

      $batchUpdate = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
        'requests' => $requests
      ]);

      $service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
    } catch (\Exception $e) {
      Log::warning("Gagal auto-resize kolom: " . $e->getMessage());
    }
  }

  /**
  * Mendapatkan sheet ID berdasarkan nama sheet.
  */
  protected function getSheetIdByName(string $spreadsheetId, string $sheetName): ?int
  {
    $spreadsheet = Sheets::spreadsheet($spreadsheetId)->get();
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
  protected function saveSpreadsheetId(TelegramUser $user, string $spreadsheetId): void
  {
    $setting = UserSetting::firstOrNew(['user_id' => $user->id]);
    $setting->google_spreadsheet_id = $spreadsheetId;
    $setting->save();
  }
}