<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Wallet;
use Brick\Money\Money;
use Brick\Money\Exception\MoneyMismatchException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WalletService
{
  protected int $cacheTtl = 3600; // 1 hour

  /**
  * Cek apakah cache driver mendukung tags.
  */
  private static function supportsTags(): bool
  {
    return Cache::getStore() instanceof \Illuminate\Cache\TaggableStore;
  }

  /**
  * Simpan cache dengan tags jika didukung, jika tidak pakai cache polos.
  * Tag sudah dikunci: ['wallets', "user_{userId}"].
  */
  private function rememberWithFallback(int $userId, string $cacheKey, int $ttl, callable $callback): mixed
  {
    if (self::supportsTags()) {
      return Cache::tags(['wallets', "user_{$userId}"])->remember($cacheKey, $ttl, $callback);
    }
    return Cache::remember($cacheKey, $ttl, $callback);
  }

  /**
  * Get all active wallets for a user with formatting (cached).
  */
  public function getUserWallets($user): array
  {
    $cacheKey = $this->getUserWalletsCacheKey($user->id);

    return $this->rememberWithFallback($user->id, $cacheKey, $this->cacheTtl, function () use ($user) {
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

    $this->clearWalletCaches($wallet->id, $user->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);

    return $wallet->fresh();
  }

  /**
  * Get detailed wallet information (cached).
  */
  public function getWalletDetail($user, Wallet $wallet): array
  {
    $this->ensureUserOwnsWallet($user, $wallet);

    $cacheKey = $this->getWalletDetailCacheKey($wallet->id);

    return $this->rememberWithFallback($user->id, $cacheKey, $this->cacheTtl, function () use ($wallet) {
      $wallet->load('currencyDetails');

      $formatted = $this->formatWalletData($wallet);
      $formatted['transaction_count'] = $wallet->transactions()->count();

      return $formatted;
    });
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

    $this->clearWalletCaches($wallet->id, $user->id);
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

    $this->clearWalletCaches($wallet->id, $user->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);

    return $result;
  }

  /**
  * Format wallet data for API response.
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
  */
  protected function ensureUserOwnsWallet($user, Wallet $wallet): void
  {
    if ($wallet->user_id !== $user->id) {
      abort(403, 'Unauthorized');
    }
  }

  /**
  * Get cache key for user's wallets list.
  */
  protected function getUserWalletsCacheKey(int $userId): string
  {
    return "user_wallets_{$userId}";
  }

  /**
  * Get cache key for single wallet detail.
  */
  protected function getWalletDetailCacheKey(int $walletId): string
  {
    return "wallet_detail_{$walletId}";
  }

  /**
  * Clear all caches related to a wallet (detail + user list).
  */
  public function clearWalletCaches(int $walletId, int $userId): void
  {
    // Hapus tag cache jika didukung
    if (self::supportsTags()) {
      Cache::tags(['wallets', "user_{$userId}"])->flush();
    } else {
      // Fallback: hapus masing-masing key
      Cache::forget($this->getUserWalletsCacheKey($userId));
      Cache::forget($this->getWalletDetailCacheKey($walletId));
    }
  }
}