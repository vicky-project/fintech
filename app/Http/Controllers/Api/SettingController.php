<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\FinTech\Models;
use Modules\FinTech\Http\Requests\UserSettingsRequest;
use Modules\Telegram\Models\TelegramUser;

class SettingController extends Controller
{
  public function show(Request $request): JsonResponse
  {
    $settings = Models\UserSetting::firstOrCreate(
      ['user_id' => $request->user()->id],
      [
        'default_currency' => config('fintech.default_currency', 'IDR'),
        'pin_enabled' => false
      ]
    );

    return response()->json([
      'success' => true,
      'data' => $settings
    ]);
  }

  public function update(UserSettingsRequest $request): JsonResponse
  {
    $settings = Models\UserSetting::updateOrCreate(
      ['user_id' => $request->user()->id],
      $request->validatedSettings()
    );

    return response()->json([
      'success' => true,
      'data' => $settings->except(['pin'])
    ]);
  }

  public function verifyPin(Request $request): JsonResponse
  {
    $request->validate([
      'pin' => 'required|string|min:4|max:6'
    ]);

    try {
      $user = $request->user();
      $settings = Models\UserSetting::firstOrCreate(
        ['user_id' => $user->id],
        [
          'default_currency' => config('fintech.default_currency', 'IDR')
        ]
      );

      if (!$settings->pin_enabled) {
        return response()->json([
          'success' => true,
          'message' => 'PIN tidak diaktifkan'
        ]);
      }

      if ($settings->isLocked()) {
        $remaining = $settings->getLockoutRemaining();
        return response()->json([
          'success' => false,
          'message' => "Akun terkunci. Silakan coba lagi dalam {$remaining}.",
          'locked_until' => $settings->locked_until->toDateTimeString()
        ], 429);
      }

      if ($settings->verifyPin($request->pin)) {
        $settings->resetAttempts();
        $settings->update(['pin_verified_at' => now()]);

        return response()->json([
          'success' => true,
          'message' => 'PIN valid'
        ]);
      }

      $settings->recordFailedAttempt();
      $attempts = $settings->pin_attempts;
      $remainingAttempts = 5 - $attempts;
      $lockedUntil = null;
      if ($settings->isLocked()) {
        $message = "PIN salah sebanyak 5 kali. Akun dikunci selama 15 menit";
        $lockedUntil = $settings->locked_until->toDateTimeString();
      } else {
        $message = "PIN salah. {$remainingAttempts} percobaan tersisa.";
      }

      return response()->json([
        'success' => false,
        'message' => $message,
        'attempts' => $attempts,
        'locked_until' => $lockedUntil
      ], 401);
    } catch(\Exception $e) {
      \Log::error("Failed to verify PIN", [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);

      return response()->json([
        'success' => false,
        'message' => 'Internal Server Error'
      ], 500);
    }
  }

  public function destroy(Request $request): JsonResponse
  {
    $user = $request->user();

    if (!$user instanceof TelegramUser) {
      \Log::warning($user);
      abort(401, 'Unauthorized');
    }

    DB::transaction(function () use ($user) {
      // 1. Hapus dari tabel child ke parent
      Models\Notification::where('user_id', $user->id)->delete();
      Models\StatementTransaction::whereHas('statement', fn($q) => $q->where('user_id', $user->id))->delete();
      Models\BankStatement::where('user_id', $user->id)->delete();
      Models\Transfer::whereHas('fromWallet', fn($q) => $q->where('user_id', $user->id))->delete();
      Models\Transfer::whereHas('toWallet', fn($q) => $q->where('user_id', $user->id))->delete();
      Models\Budget::where('user_id', $user->id)->delete();
      Models\Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))->delete();
      Models\UserSetting::where('user_id', $user->id)->delete();
      Models\Wallet::where('user_id', $user->id)->delete();
    });

    return response()->json([
      'success' => true,
      'message' => 'Akun dan seluruh data Anda telah dihapus permanen.',
    ]);
  }
}