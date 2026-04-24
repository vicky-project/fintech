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

      if ($settings->locked_until && now()->lt($settings->locked_until)) {
        $remaining = now()->diffForHumans($settings->locked_until, true);
        return response()->json([
          'success' => false,
          'message' => "Akun terkunci selama {$remaining}. Silakan coba lagi nanti.",
          'locked_until' => $settings->locked_until->toDateTimeString()
        ], 429);
      }

      if ($settings->verifyPin($request->pin)) {
        $settings->update([
          'pin_attempts' => 0,
          'locked_until' => null
        ]);
        session(['pin_verified_at' => now()]);
        return response()->json([
          'success' => true,
          'message' => 'PIN valid'
        ]);
      }

      $attempts = $settings->pin_attempts + 1;
      $data['pin_attempts' => $attempts];

      if ($attempts >= 5) {
        $data['locked_until'] = now()->addMinutes(15);
        $message = "PIN salah sebanyak 5 kali. Akun dikunci selama 15 menit";
      } else {
        $remainingAttempts = 5 - $attempts;
        $message = "PIN salah. {$remainingAttempts} percobaab tersis.";
      }

      $settings->update($data);

      return response()->json([
        'success' => false,
        'message' => $message,
        'locked_until' => $data['locked_until'] ?? null
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