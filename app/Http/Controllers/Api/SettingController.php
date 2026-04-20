<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\FinTech\Models\UserSetting;

class SettingController extends Controller
{
  public function show(Request $request): JsonResponse
  {
    $settings = UserSetting::firstOrCreate(
      ['user_id' => $request->user()->id],
      ['default_currency' => config('fintect.default_currency', 'IDR')]
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
    ]);

    $settings = UserSetting::updateOrCreate(
      ['user_id' => $request->user()->id],
      $validated
    );

    return response()->json([
      'success' => true,
      'data' => $settings
    ]);
  }
}