<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Wallet;
use Brick\Money\Money;
use Brick\Money\Exception\MoneyMismatchException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WalletService
{
  /**
  * Cache TTL in seconds (1 hour).
  */
  protected int $cacheTtl = 3600;

  /**
  * Get all active wallets for a user with formatting (cached).
  *
  * @param \Illuminate\Contracts\Auth\Authenticatable $user
  * @return array
  */
  public function getUserWallets($user): array
  {
    $cacheKey = $this->getUserWalletsCacheKey($user->id);

    return Cache::remember($cacheKey, $this->cacheTtl, function () use ($user) {
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
  * Create a new wallet, clear related caches.
  *
  * @param \Illuminate\Contracts\Auth\Authenticatable $user
  * @param array $data
  * @return Wallet
  * @throws MoneyMismatchException
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

    $this->clearUserWalletsCache($user->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);

    return $wallet->fresh();
  }

  /**
  * Get detailed wallet information (cached).
  *
  * @param \Illuminate\Contracts\Auth\Authenticatable $user
  * @param Wallet $wallet
  * @return array
  * @throws \Symfony\Component\HttpKernel\Exception\HttpException
  */
  public function getWalletDetail($user, Wallet $wallet): array
  {
    $this->ensureUserOwnsWallet($user, $wallet);

    $cacheKey = $this->getWalletDetailCacheKey($wallet->id);

    $data = Cache::remember($cacheKey, $this->cacheTtl, function () use ($wallet) {
      $wallet->load('currencyDetails');

      $formatted = $this->formatWalletData($wallet);
      $formatted['transaction_count'] = $wallet->transactions()->count();

      return $formatted;
    });

    // Re-attach transaction_count fresh? Already included in cache.
    // If you need real-time transaction count, you can disable caching for count.
    // But for consistency, we cache it.

    return $data;
  }

  /**
  * Update wallet, clear related caches.
  *
  * @param \Illuminate\Contracts\Auth\Authenticatable $user
  * @param Wallet $wallet
  * @param array $data
  * @return Wallet
  * @throws \Symfony\Component\HttpKernel\Exception\HttpException
  */
  public function updateWallet($user, Wallet $wallet, array $data): Wallet
  {
    $this->ensureUserOwnsWallet($user, $wallet);

    $updatableFields = array_intersect_key($data, array_flip(['name', 'description', 'is_active']));

    DB::transaction(function () use ($wallet, $updatableFields) {
      $wallet->update($updatableFields);
    });

    $this->clearWalletCaches($wallet->id, $user->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);

    return $wallet->fresh();
  }

  /**
  * Delete a wallet (soft delete or force delete) and clear caches.
  *
  * @param \Illuminate\Contracts\Auth\Authenticatable $user
  * @param Wallet $wallet
  * @return bool|null
  * @throws \Symfony\Component\HttpKernel\Exception\HttpException
  */
  public function deleteWallet($user, Wallet $wallet): ?bool
  {
    $this->ensureUserOwnsWallet($user, $wallet);

    $result = DB::transaction(function () use ($wallet) {
      return $wallet->delete();
    });

    $this->clearWalletCaches($wallet->id, $user->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);

    return $result;
  }

  /**
  * Format wallet data for API response.
  *
  * @param Wallet $wallet
  * @return array
  */
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

  /**
  * Ensure the authenticated user owns the wallet.
  *
  * @param \Illuminate\Contracts\Auth\Authenticatable $user
  * @param Wallet $wallet
  * @throws \Symfony\Component\HttpKernel\Exception\HttpException
  */
  protected function ensureUserOwnsWallet($user, Wallet $wallet): void
  {
    if ($wallet->user_id !== $user->id) {
      abort(403, 'Unauthorized');
    }
  }

  /**
  * Get cache key for user's wallets list.
  *
  * @param int $userId
  * @return string
  */
  protected function getUserWalletsCacheKey(int $userId): string
  {
    return "user_wallets_{$userId}";
  }

  /**
  * Get cache key for single wallet detail.
  *
  * @param int $walletId
  * @return string
  */
  protected function getWalletDetailCacheKey(int $walletId): string
  {
    return "wallet_detail_{$walletId}";
  }

  /**
  * Clear cache for user's wallets list.
  *
  * @param int $userId
  * @return void
  */
  public function clearUserWalletsCache(int $userId): void
  {
    Cache::forget($this->getUserWalletsCacheKey($userId));
  }

  /**
  * Clear cache for a specific wallet detail.
  *
  * @param int $walletId
  * @return void
  */
  public function clearWalletDetailCache(int $walletId): void
  {
    Cache::forget($this->getWalletDetailCacheKey($walletId));
  }

  /**
  * Clear all caches related to a wallet (detail + user list).
  *
  * @param int $walletId
  * @param int $userId
  * @return void
  */
  public function clearWalletCaches(int $walletId, int $userId): void
  {
    $this->clearWalletDetailCache($walletId);
    $this->clearUserWalletsCache($userId);
  }
}