<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Modules\FinTech\Models\UserSetting;
use Illuminate\Support\Facades\Log;
use Modules\Telegram\Models\TelegramUser;
use Modules\Telegram\Services\Support\TelegramApi;

class GoogleOAuthController extends Controller
{
  /**
  * Redirect pengguna ke Google untuk login & izin.
  * State berisi telegram_id user (encrypted).
  */
  public function redirect(Request $request) {
    $user = $request->user();
    if (!$user->telegram_id) {
      return response()->json(['error' => 'Akun Telegram tidak terdeteksi.'], 400);
    }

    $state = $this->encodeState([
      'telegram_id' => $user->telegram_id,
      'random' => Str::random(16),
    ]);

    $client = $this->createClient();
    $client->setState($state);
    $authUrl = $client->createAuthUrl();

    return response()->json(['url' => $authUrl]);
  }

  /**
  * Callback dari Google.
  */
  public function callback(Request $request) {
    if (!$request->has('code') || !$request->has('state')) {
      return response()->json(['error' => 'Parameter tidak lengkap.'], 400);
    }

    // Verifikasi state
    $stateData = $this->decodeState($request->state);
    if (!$stateData || !isset($stateData['telegram_id'])) {
      return response()->json(['error' => 'State tidak valid.'], 400);
    }

    $telegramId = $stateData['telegram_id'];

    // Cari user berdasarkan telegram_id
    $user = TelegramUser::where('telegram_id', $telegramId)->first();
    if (!$user) {
      return response()->json(['error' => 'Pengguna tidak ditemukan.'], 404);
    }

    $client = $this->createClient();
    $token = $client->fetchAccessTokenWithAuthCode($request->code);

    if (isset($token['error'])) {
      Log::error('Google OAuth gagal', $token);
      return response()->json(['error' => 'Gagal mendapatkan token: ' . $token['error']], 500);
    }

    // Simpan token ke user_settings
    $setting = UserSetting::firstOrNew(['user_id' => $user->id]);
    $setting->google_access_token = $token['access_token'];
    $setting->google_refresh_token = $token['refresh_token'] ?? null;
    if (isset($token['expires_in'])) {
      $setting->google_token_expires_at = now()->addSeconds($token['expires_in']);
    }
    $setting->save();

    Log::info("Google OAuth berhasil untuk user {$user->id}");



    return response()->json(['message' => 'Akun Google berhasil terhubung.']);
  }

  public function callback(Request $request) {
    if (!$request->has('code') || !$request->has('state')) {
      return response('Parameter tidak lengkap.', 400);
    }

    $stateData = $this->decodeState($request->state);
    if (!$stateData || !isset($stateData['telegram_id'])) {
      return response('State tidak valid.', 400);
    }

    $telegramId = $stateData['telegram_id'];
    $user = TelegramUser::where('telegram_id', $telegramId)->first();

    if (!$user) {
      return response('Pengguna tidak ditemukan.', 404);
    }

    $client = $this->createClient();
    $token = $client->fetchAccessTokenWithAuthCode($request->code);

    if (isset($token['error'])) {
      Log::error('Google OAuth gagal', $token);
      return response('Gagal mendapatkan token: ' . $token['error'], 500);
    }

    // Simpan token
    $setting = UserSetting::firstOrNew(['user_id' => $user->id]);
    $setting->google_access_token = $token['access_token'];
    $setting->google_refresh_token = $token['refresh_token'] ?? null;
    if (isset($token['expires_in'])) {
      $setting->google_token_expires_at = now()->addSeconds($token['expires_in']);
    }
    $setting->save();

    Log::info("Google OAuth berhasil untuk user {$user->id}");

    // Kirim pesan sukses via Telegram
    try {
      $telegramApi = app(TelegramApi::class);
      $telegramApi->sendMessage(
        chatId: $telegramId,
        text: "✅ Akun Google Anda berhasil terhubung!\nSekarang Anda bisa mengekspor data ke Google Sheets."
      );
    } catch (\Exception $e) {
      Log::warning('Gagal kirim notif Telegram: ' . $e->getMessage());
    }

    // Tampilkan halaman yang otomatis tertutup
    return response(<<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Google Terhubung</title>
    <style>
        body { font-family: sans-serif; text-align: center; padding-top: 60px; }
        .success { font-size: 48px; }
        .message { color: #4CAF50; font-size: 18px; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="success">✅</div>
    <div class="message">Akun Google berhasil terhubung!</div>
    <script>
        // Tutup halaman ini setelah 1.5 detik
        setTimeout(() => {
            window.close();
        }, 1500);
    </script>
</body>
</html>
HTML);
}

  /**
  * Cek status koneksi Google user.
  */
  public function status(Request $request) {
    $user = $request->user();
    $setting = UserSetting::where('user_id', $user->id)->first();

    $connected = $setting && $setting->google_access_token;

    return response()->json(['connected' => (bool) $connected]);
  }

  /**
  * Buat Google Client dengan konfigurasi OAuth.
  */
  protected function createClient(): GoogleClient
  {
    $client = new GoogleClient();
    $client->setClientId(config('fintech.google.oauth_client_id'));
    $client->setClientSecret(config('fintech.google.oauth_client_secret'));
    $client->setRedirectUri(config('fintech.google.oauth_redirect_uri'));
    $client->addScope(Sheets::SPREADSHEETS);
    $client->setAccessType('offline');
    $client->setPrompt('consent');

    return $client;
  }

  /**
  * Encode state dengan hash untuk verifikasi.
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
}