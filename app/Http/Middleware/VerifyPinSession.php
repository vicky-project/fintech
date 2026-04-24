<?php

namespace Modules\FinTech\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\FinTech\Models\UserSetting;

class VerifyPinSession
{
  /**
  * Handle an incoming request.
  */
  public function handle(Request $request, Closure $next) {
    $user = $request->user();

    // Cek apakah user mengaktifkan PIN
    $settings = UserSetting::where('user_id', $user->id)->first();

    // Jika PIN tidak diaktifkan, lewati middleware
    if (!$settings || !$settings->pin_enabled) {
      return $next($request);
    }

    // Cek apakah sesi PIN sudah diverifikasi
    $pinVerifiedAt = session('pin_verified_at');

    if (!$pinVerifiedAt) {
      return response()->json([
        'success' => false,
        'message' => 'PIN belum diverifikasi.',
        'code' => 'PIN_REQUIRED'
      ], 403);
    }

    // Cek timeout (3 menit, sinkron dengan frontend)
    $timeout = 3 * 60; // 180 detik
    if (now()->diffInSeconds($pinVerifiedAt) > $timeout) {
      session()->forget('pin_verified_at');
      return response()->json([
        'success' => false,
        'message' => 'Sesi PIN telah berakhir. Silakan masukkan PIN kembali.',
        'code' => 'PIN_EXPIRED'
      ], 403);
    }

    // Perbarui timestamp terakhir aktivitas (opsional, untuk sliding session)
    session(['pin_verified_at' => now()]);

    return $next($request);
  }
}