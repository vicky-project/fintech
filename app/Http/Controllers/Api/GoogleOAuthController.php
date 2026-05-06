<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\Google\GoogleAuthService;
use Modules\Telegram\Services\Support\TelegramApi;

class GoogleOAuthController extends Controller
{
  public function __construct(
    protected GoogleAuthService $authService,
    protected TelegramApi $telegramApi
  ) {}

  public function redirect(Request $request): JsonResponse
  {
    $user = $request->user();
    if (!$user->telegram_id) {
      return response()->json(['error' => 'Akun Telegram tidak terdeteksi.'], 400);
    }

    $url = $this->authService->getAuthorizationUrl($user);

    return response()->json(['url' => $url]);
  }

  public function status(Request $request): JsonResponse
  {
    $connected = $this->authService->isConnected($request->user());

    return response()->json(['connected' => $connected]);
  }

  public function disconnect(Request $request): JsonResponse
  {
    $user = $request->user();
    $this->authService->disconnect($user);

    // Notifikasi Telegram
    try {
      $this->telegramApi->sendMessage(
        chatId: $user->telegram_id,
        text: "❌️ Akun Google Anda berhasil diputuskan!\nHubungkan kembali untuk mengekspor ke Google Sheets."
      );
    } catch (\Exception $e) {
      \Log::warning('Gagal kirim notif Telegram: ' . $e->getMessage());
    }

    return response()->json(['message' => 'Koneksi Google telah diputus.']);
  }

  public function callback(Request $request) {
    if (!$request->has('code') || !$request->has('state')) {
      return response('Parameter tidak lengkap.', 400);
    }

    try {
      $this->authService->handleCallback($request->code, $request->state);
    } catch (\InvalidArgumentException $e) {
      return response($e->getMessage(), 400);
    } catch (\RuntimeException $e) {
      return response($e->getMessage(), 500);
    }

    // Notifikasi Telegram (opsional)
    try {
      $telegramId = $this->authService->getStateData($request->state)['telegram_id'];
      $this->telegramApi->sendMessage(
        chatId: $telegramId,
        text: "✅ Akun Google Anda berhasil terhubung!\nSekarang Anda bisa mengekspor data ke Google Sheets."
      );
    } catch (\Exception $e) {
      \Log::warning('Gagal kirim notif Telegram: ' . $e->getMessage());
    }

    return response(<<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Google Terhubung</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: sans-serif; text-align: center; padding: 20px; }
        .checkmark { font-size: 64px; color: #4CAF50; }
    </style>
</head>
<body>
    <div class="checkmark">✅</div>
    <h2>Akun Google Berhasil Terhubung!</h2>
    <p>Silakan tutup halaman ini dan kembali ke aplikasi.</p>
</body>
</html>
HTML);
    }
}