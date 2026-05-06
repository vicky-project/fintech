<?php

namespace Modules\FinTech\Services\Google;

use Google\Client as GoogleClient;
use Modules\FinTech\Models\UserSetting;
use Modules\Telegram\Models\TelegramUser;
use Illuminate\Support\Facades\Log;

class GoogleAuthService
{
  protected GoogleClient $client;
  protected SpreadsheetManager $spreadsheetManager;

  public function __construct(
    GoogleClientFactory $clientFactory,
    SpreadsheetManager $spreadsheetManager
  ) {
    $this->client = $clientFactory->create();
    $this->client->setPrompt('consent');
    $this->spreadsheetManager = $spreadsheetManager;
  }

  /**
  * Buat URL otorisasi Google.
  */
  public function getAuthorizationUrl(TelegramUser $user): string
  {
    $state = $this->encodeState([
      'telegram_id' => $user->telegram_id,
      'random' => Str::random(16),
    ]);
    $this->client->setState($state);
    return $this->client->createAuthUrl();
  }

  /**
  * Proses callback OAuth, simpan token, dan buat spreadsheet.
  */
  public function handleCallback(string $authCode, string $state): void
  {
    // Verifikasi state
    $stateData = $this->decodeState($state);
    if (!$stateData || !isset($stateData['telegram_id'])) {
      throw new \InvalidArgumentException('State tidak valid.');
    }

    $telegramId = $stateData['telegram_id'];
    $user = TelegramUser::where('telegram_id', $telegramId)->firstOrFail();

    // Dapatkan token
    $token = $this->client->fetchAccessTokenWithAuthCode($authCode);
    if (isset($token['error'])) {
      Log::error('Google OAuth gagal', $token);
      throw new \RuntimeException('Gagal mendapatkan token: ' . $token['error']);
    }

    // Simpan token
    $setting = UserSetting::firstOrNew(['user_id' => $user->id]);
    $setting->google_access_token = $token['access_token'];
    $setting->google_refresh_token = $token['refresh_token'] ?? null;
    if (isset($token['expires_in'])) {
      $setting->google_token_expires_at = now()->addSeconds($token['expires_in']);
    }
    $setting->save();

    // Buat spreadsheet jika belum ada
    if (!$setting->google_spreadsheet_id) {
      $spreadsheetId = $this->spreadsheetManager->getOrCreateSpreadsheet($user);
      $setting->google_spreadsheet_id = $spreadsheetId;
      $setting->save();
    }

    Log::info("Google OAuth berhasil untuk user {$user->id}");
  }

  /**
  * Cek status koneksi Google.
  */
  public function isConnected(TelegramUser $user): bool
  {
    $setting = UserSetting::where('user_id', $user->id)->first();
    return $setting && !empty($setting->google_access_token);
  }

  /**
  * Putuskan koneksi Google.
  */
  public function disconnect(TelegramUser $user): void
  {
    $setting = UserSetting::where('user_id', $user->id)->first();
    if ($setting) {
      $setting->google_access_token = null;
      $setting->google_refresh_token = null;
      $setting->google_token_expires_at = null;
      $setting->google_spreadsheet_id = null;
      $setting->save();
    }
  }

  /**
  * Encode state dengan hash.
  */
  protected function encodeState(array $data): string
  {
    $json = json_encode($data);
    $hash = hash_hmac('sha256', $json, config('app.key'));
    return base64_encode($json . '::' . $hash);
  }

  /**
  * Decode state dan verifikasi hash.
  */
  protected function decodeState(string $state): ?array
  {
    $decoded = base64_decode($state);
    $parts = explode('::', $decoded);
    if (count($parts) !== 2) return null;

    [$json,
      $hash] = $parts;
    $expectedHash = hash_hmac('sha256', $json, config('app.key'));
    if (!hash_equals($expectedHash, $hash)) return null;

    return json_decode($json, true);
  }

  /**
  * Ambil data dari state tanpa verifikasi hash.
  * Hanya untuk keperluan notifikasi atau logging.
  */
  public function getStateData(string $state): ?array
  {
    $decoded = base64_decode($state);
    $parts = explode('::', $decoded);
    if (count($parts) !== 2) return null;

    $json = $parts[0]; // abaikan hash
    return json_decode($json, true);
  }
}