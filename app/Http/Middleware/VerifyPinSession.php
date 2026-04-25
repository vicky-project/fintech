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
    $settings = \Modules\FinTech\Models\UserSetting::where('user_id', $user->id)->first();

    // Jika PIN tidak diaktifkan, lewati middleware
    if (!$settings || !$settings->pin_enabled) {
      return $next($request);
    }

    // Hanya cek apakah session pin_verified_at ada
    if (!session('pin_verified_at')) {
      return response()->json([
        'success' => false,
        'message' => 'PIN belum diverifikasi.',
        'code' => 'PIN_REQUIRED'
      ], 403);
    }

    return $next($request);
  }
}