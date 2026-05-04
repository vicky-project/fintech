<?php

namespace Modules\FinTech\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\FinTech\Models\UserSetting;

class VerifyPinSession
{
  public function handle(Request $request, Closure $next) {
    $user = $request->user();
    $settings = UserSetting::where('user_id', $user->id)->first();

    // Jika PIN tidak diaktifkan, lewati
    if (!$settings || !$settings->pin_enabled) {
      return $next($request);
    }

    // Cek apakah PIN sudah diverifikasi
    if (!$settings->pin_verified_at) {
      return response()->json([
        'success' => false,
        'message' => 'PIN belum diverifikasi.',
        'code' => 'PIN_REQUIRED'
      ], 403);
    }

    // Opsional: cek timeout 3 menit
    $timeout = 3 * 60;
    if (now()->diffInSeconds($settings->pin_verified_at) > $timeout) {
      $settings->update(['pin_verified_at' => null]);
      return response()->json([
        'success' => false,
        'message' => 'Sesi PIN telah berakhir.',
        'code' => 'PIN_EXPIRED'
      ], 403);
    }

    // Perbarui timestamp terakhir
    $settings->update(['pin_verified_at' => now()]);

    return $next($request);
  }
}