<?php

namespace Modules\FinTech\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\FinTech\Models\UserSetting;
use Modules\FinTech\Services\Google\GoogleSheetsClient;
use Modules\FinTech\Services\Google\SpreadsheetManager;
use Google\Service\Sheets\ValueRange;
use Modules\Telegram\Models\TelegramUser;

class SyncTransactionToSheetJob implements ShouldQueue
{
  use Dispatchable,
  Queueable;

  public function __construct(
    protected Authenticatable $user,
    protected array $transactionData
  ) {}

  public function handle(GoogleSheetsClient $client, SpreadsheetManager $manager): void
  {
    // 1. Ambil pengaturan user
    $setting = UserSetting::where('user_id', $this->user->id)->first();
    \Log::debug('setting', [
      'setting' => $setting,
      'preferences' => $setting->preferences,
      'google' => $setting->preferences['auto_sync_google']
    ]);
    if (!$setting) return;

    // 2. Cek preferensi auto‑sync
    $prefs = $setting->preferences ?? [];
    if (empty($prefs['auto_sync_google'])) return;

    // 3. Cek apakah Google sudah terhubung
    if (!$setting->google_access_token) return;

    // 4. Setup client Google
    try {
      $client->setupForUser($this->user);
    } catch (\Exception $e) {
      \Log::warning('SyncTransactionToSheet: Gagal setup Google client', [
        'error' => $e->getMessage(),
        'user' => $this->user
      ]);
      return;
    }

    $spreadsheetId = $setting->google_spreadsheet_id;
    if (!$spreadsheetId) return;

    $sheetName = 'Live Feed';

    try {
      // 5. Pastikan sheet "Live Feed" ada
      $manager->addSheetIfNotExists($spreadsheetId, $sheetName);

      // 6. Tambahkan baris data
      $this->appendRow($client, $spreadsheetId, $sheetName);
    } catch (\Exception $e) {
      \Log::warning('SyncTransactionToSheet: Gagal menambah baris', [
        'error' => $e->getMessage(),
        'user' => $this->user
      ]);
    }
  }

  protected function appendRow(GoogleSheetsClient $client, string $spreadsheetId, string $sheetName): void
  {
    $service = $client->getSheetsService();

    // Ambil jumlah baris yang sudah ada
    $response = $service->spreadsheets_values->get($spreadsheetId, $sheetName);
    $values = $response->getValues() ?? [];

    // Jika sheet kosong, tambahkan header
    if (empty($values)) {
      $headerRange = $sheetName . '!A1';
      $headerBody = new ValueRange([
        'values' => [['Tanggal', 'Tipe', 'Kategori', 'Dompet', 'Pemasukan', 'Pengeluaran', 'Deskripsi']]
      ]);
      $service->spreadsheets_values->update($spreadsheetId, $headerRange, $headerBody, [
        'valueInputOption' => 'RAW'
      ]);
    }

    // Tambahkan baris data di akhir
    $row = [
      $this->transactionData['Tanggal'],
      $this->transactionData['Tipe'],
      $this->transactionData['Kategori'],
      $this->transactionData['Dompet'],
      $this->transactionData['Pemasukan'],
      $this->transactionData['Pengeluaran'],
      $this->transactionData['Deskripsi'],
    ];

    $range = $sheetName . '!A' . (count($values) + 1);
    $body = new ValueRange(['values' => [$row]]);
    $service->spreadsheets_values->update($spreadsheetId, $range, $body, [
      'valueInputOption' => 'RAW'
    ]);
  }
}