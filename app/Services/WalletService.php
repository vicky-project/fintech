<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Traits\HasUserCache;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

class WalletService
{
  use HasUserCache;

  protected int $cacheTtl = 3600;

  /**
  * Get all active wallets for a user with formatting (cached).
  */
  public function getUserWallets($user): array
  {
    $suffix = 'wallets';

    return $this->rememberForUser($user->id, $suffix, $this->cacheTtl, function () use ($user) {
      return Wallet::where('user_id', $user->id)
      ->where('is_active', true)
      ->with('currencyDetails')
      ->orderBy('name')
      ->get()
      ->map(fn($wallet) => $this->formatWalletData($wallet))
      ->toArray();
    });
  }

  /**
  * Get detailed wallet information (cached).
  */
  public function getWalletDetail($user, Wallet $wallet): array
  {
    $this->ensureUserOwnsWallet($user, $wallet);

    $suffix = "wallet_detail_{$wallet->id}";

    return $this->rememberForUser($user->id, $suffix, $this->cacheTtl, function () use ($wallet) {
      $wallet->load('currencyDetails');
      $formatted = $this->formatWalletData($wallet);
      $formatted['transaction_count'] = $wallet->transactions()->count();
      return $formatted;
    });
  }

  /**
  * Create a new wallet, clear related caches.
  */
  public function createWallet($user, array $data): Wallet
  {
    $initialBalance = Money::of(
      $data['initial_balance'] ?? 0,
      $data['currency'] ?? 'IDR'
    );

    $wallet = DB::transaction(function () use ($user, $data, $initialBalance) {
      $wallet = new Wallet();
      $wallet->user_id = $user->id;
      $wallet->name = $data['name'];
      $wallet->currency = $data['currency'] ?? 'IDR';
      $wallet->description = $data['description'] ?? null;
      $wallet->balance = $initialBalance;
      $wallet->save();
      return $wallet;
    });

    $this->clearUserCache($user->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);

    return $wallet->fresh();
  }

  /**
  * Update wallet, clear related caches.
  */
  public function updateWallet($user, Wallet $wallet, array $data): Wallet
  {
    $this->ensureUserOwnsWallet($user, $wallet);

    $updatableFields = array_intersect_key($data, array_flip(['name', 'description', 'is_active']));

    DB::transaction(function () use ($wallet, $updatableFields) {
      $wallet->update($updatableFields);
    });

    $this->clearUserCache($user->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);

    return $wallet->fresh();
  }

  /**
  * Delete a wallet and clear caches.
  */
  public function deleteWallet($user, Wallet $wallet): ?bool
  {
    $this->ensureUserOwnsWallet($user, $wallet);

    $result = DB::transaction(function () use ($wallet) {
      return $wallet->delete();
    });

    $this->clearUserCache($user->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);

    return $result;
  }

  // ─── HELPERS ───────────────────────────────────────────

  protected function formatWalletData(Wallet $wallet): array
  {
    $currencyDetails = $wallet->currencyDetails;

    return [
      'id' => $wallet->id,
      'name' => $wallet->name,
      'balance' => $wallet->getBalanceFloat(),
      'formatted_balance' => $wallet->getFormattedBalance(),
      'currency' => [
        'code' => $wallet->currency,
        'name' => $currencyDetails->name ?? $wallet->currency,
        'symbol' => $currencyDetails->symbol ?? $wallet->currency,
        'precision' => $currencyDetails->precision ?? 2,
      ],
      'description' => $wallet->description,
      'is_active' => $wallet->is_active,
    ];
  }

  protected function ensureUserOwnsWallet($user, Wallet $wallet): void
  {
    if ($wallet->user_id !== $user->id) {
      abort(403, 'Unauthorized');
    }
  }

  // ─── Trait Override (opsional) ──────────────────────

  protected function knownUserCacheSuffixes(int $userId): array
  {
    return [
      'wallets',
      'budgets',
      'insights',
    ];
  }
}