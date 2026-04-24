<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Modules\FinTech\Models\UserSetting;

class SettingController extends Controller
{
  public function show(Request $request): JsonResponse
  {
    $settings = UserSetting::firstOrCreate(
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

  public function update(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'default_currency' => [
        'sometimes',
        'string',
        'size:3',
        Rule::exists('world_currencies', 'code')
      ],
      'default_wallet_id' => [
        'sometimes',
        'nullable',
        Rule::exists('fintech_wallets', 'id')->where(function ($query) use ($request) {
          $query->where('user_id', $request->user()->id);
        }),
      ],
      'pin_enabled' => 'sometimes|boolean',
      'pin' => 'nullable|string|min:4|max:6|required_if:pin_enabled,true',
    ]);

    if (isset($validated['pin_enabled']) && $validated['pin_enabled'] === false) {
      $validated['pin'] = null;
    }

    $settings = UserSetting::updateOrCreate(
      ['user_id' => $request->user()->id],
      $validated
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
      $settings = UserSetting::firstOrCreate(
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
        session(['pin_verified_at' => now()]);
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
        $message = "PIN salah. {$remainingAttempts} percobaab tersisa.";
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
}