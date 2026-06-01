<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Models\UserSetting;

class SettingController extends Controller
{
  use ResolvesTelegramUser;

  public function index(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $settings = UserSetting::where('user_id', $telegramUser->id)->first();
    $maritalStatuses = \Modules\FinTech\Enums\MaritalStatus::options();
    $wallets = app(WalletService::class)->getUserWallets($telegramUser);

    return view('fintech::web.settings.index', compact('settings', 'maritalStatuses', 'wallets'));
  }

  public function update(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $settings = UserSetting::firstOrCreate(['user_id' => $telegramUser->id]);

    $validated = $request->validate([
      'default_currency' => 'nullable|string|size:3',
      'default_wallet_id' => 'nullable|exists:fintech_wallets,id',
      'pin_enabled' => 'boolean',
      'pin' => 'nullable|string|min:4|max:6',
      'marital_status' => 'nullable|string|in:single,married,divorced,widowed',
      'dependents' => 'nullable|integer|min:0|max:10',
      'notification_telegram' => 'nullable|boolean',
      'auto_sync_google' => 'nullable|boolean',
    ]);

    // Konversi preferences
    $prefs = $settings->preferences ?? [];
    if (array_key_exists('notification_telegram', $validated)) {
      $prefs['notification_telegram'] = (bool) $validated['notification_telegram'];
      unset($validated['notification_telegram']);
    }
    if (array_key_exists('auto_sync_google', $validated)) {
      $prefs['auto_sync_google'] = (bool) $validated['auto_sync_google'];
      unset($validated['auto_sync_google']);
    }
    $validated['preferences'] = $prefs;

    if (empty($validated['pin'])) {
      unset($validated['pin']);
    }

    $settings->fill($validated)->save();

    return back()->with('success', 'Pengaturan berhasil disimpan.');
  }
}