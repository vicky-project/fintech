<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Notification;
use Modules\FinTech\Models\Budget;
use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Enums\NotificationType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class NotificationService
{
  protected int $cacheTtl = 3600;

  /**
  * Cek apakah cache driver mendukung tags.
  */
  private static function supportsTags(): bool
  {
    return Cache::getStore() instanceof \Illuminate\Cache\TaggableStore;
  }

  /**
  * Simpan cache dengan tags jika didukung, jika tidak pakai cache polos.
  * Tag dikunci: ['notifications', "user_{userId}"].
  */
  private function rememberWithFallback(int $userId, string $cacheKey, int $ttl, callable $callback): mixed
  {
    if (self::supportsTags()) {
      return Cache::tags(['notifications', "user_{$userId}"])->remember($cacheKey, $ttl, $callback);
    }
    return Cache::remember($cacheKey, $ttl, $callback);
  }

  /**
  * Clear semua cache notifikasi user tertentu.
  */
  public static function clearNotificationCaches(int $userId): void
  {
    try {
      if (self::supportsTags()) {
        Cache::tags(['notifications', "user_{$userId}"])->flush();
      } else {
        Cache::forget("notifications_user_{$userId}_limit_50");
        Cache::forget("notifications_unread_count_{$userId}");
      }
    } catch (\Exception $e) {
      \Log::warning('Failed to clear notification caches: ' . $e->getMessage());
    }
  }

  /**
  * Membuat notifikasi baru.
  */
  public function create(int $userId, NotificationType $type, string $title, string $message, array $data = []): Notification
  {
    $notification = Notification::create([
      'user_id' => $userId,
      'type' => $type,
      'title' => $title,
      'message' => $message,
      'data' => $data,
    ]);

    self::clearNotificationCaches($userId);

    return $notification;
  }

  /**
  * Mengambil notifikasi user (belum dibaca dulu, baru yang sudah dibaca).
  */
  public function getForUser(int $userId, int $limit = 50): array
  {
    $cacheKey = "notifications_user_{$userId}_limit_{$limit}";
    return $this->rememberWithFallback($userId, $cacheKey, $this->cacheTtl, function () use ($userId, $limit) {
      return Notification::where('user_id', $userId)
      ->orderBy('is_read', 'asc')
      ->orderBy('created_at', 'desc')
      ->limit($limit)
      ->get()
      ->toArray();
    });
  }

  /**
  * Menandai satu notifikasi sudah dibaca.
  */
  public function markAsRead(int $notificationId, int $userId): void
  {
    $notification = Notification::where('id', $notificationId)
    ->where('user_id', $userId)
    ->first();

    if ($notification) {
      $notification->markAsRead();
      self::clearNotificationCaches($userId);
    }
  }

  /**
  * Menandai semua notifikasi user sudah dibaca.
  */
  public function markAllAsRead(int $userId): void
  {
    Notification::where('user_id', $userId)
    ->unread()
    ->update([
      'is_read' => true,
      'read_at' => now(),
    ]);
    self::clearNotificationCaches($userId);
  }

  /**
  * Menghitung jumlah notifikasi belum dibaca.
  */
  public function getUnreadCount(int $userId): int
  {
    $cacheKey = "notifications_unread_count_{$userId}";
    return $this->rememberWithFallback($userId, $cacheKey, 300, function () use ($userId) {
      return Notification::where('user_id', $userId)
      ->unread()
      ->count();
    });
  }

  /**
  * Generate notifikasi untuk SEMUA user (dipanggil oleh scheduler).
  */
  public function generateForAllUsers(): void
  {
    // Ambil user IDs yang memiliki transaksi dalam 3 bulan terakhir
    $activeUsers = DB::table('fintech_transactions')
    ->join('fintech_wallets', 'fintech_transactions.wallet_id', '=', 'fintech_wallets.id')
    ->where('fintech_transactions.transaction_date', '>', Carbon::now()->subMonths(3))
    ->whereNotNull('fintech_wallets.user_id')
    ->distinct()
    ->pluck('fintech_wallets.user_id');

    foreach ($activeUsers as $userId) {
      $this->checkBudgetWarnings($userId);
      $this->checkCashflowProjection($userId);
    }
  }

  /**
  * Cek budget yang >80% dan buat notifikasi.
  */
  public function checkBudgetWarnings(int $userId): void
  {
    $budgets = Budget::where('user_id', $userId)
    ->where('is_active', true)
    ->with('category')
    ->get();

    foreach ($budgets as $budget) {
      $percentage = $budget->getPercentage();
      $type = NotificationType::BUDGET_WARNING;

      if ($percentage >= 80 && $percentage < 100) {
        $exists = Notification::where('user_id', $userId)
        ->where('type', $type)
        ->where('data->budget_id', $budget->id)
        ->where('created_at', '>', Carbon::now()->subDay())
        ->exists();

        if (!$exists) {
          $this->create(
            $userId,
            $type,
            'Budget Hampir Habis',
            "Budget {$budget->category->name} telah mencapai {$percentage}% (Rp " .
            number_format($budget->getCurrentSpending(), 0, ',', '.') . " dari Rp " .
            number_format($budget->getAmountFloat(), 0, ',', '.') . ").",
            ['budget_id' => $budget->id, 'category_id' => $budget->category_id]
          );
        }
      }

      if ($percentage >= 100) {
        $exists = Notification::where('user_id', $userId)
        ->where('type', $type)
        ->where('data->budget_id', $budget->id)
        ->where('created_at', '>', Carbon::now()->subDay())
        ->exists();

        if (!$exists) {
          $this->create(
            $userId,
            $type,
            'Budget Terlampaui',
            "Budget {$budget->category->name} telah terlampaui ({$percentage}%). Segera kurangi pengeluaran.",
            ['budget_id' => $budget->id, 'category_id' => $budget->category_id]
          );
        }
      }
    }
  }

  /**
  * Proyeksi arus kas 7 hari ke depan.
  */
  public function checkCashflowProjection(int $userId): void
  {
    $avgDailyExpense = Transaction::expense()
    ->whereHas('wallet', fn($q) => $q->where('user_id', $userId))
    ->where('transaction_date', '>', Carbon::now()->subDays(30))
    ->sum(DB::raw('amount / 100')) / 30;

    $totalBalance = Wallet::where('user_id', $userId)
    ->where('is_active', true)
    ->get()
    ->sum(fn($wallet) => $wallet->getBalanceFloat());

    $estimatedNeeded = $avgDailyExpense * 7;
    if ($totalBalance < $estimatedNeeded && $estimatedNeeded > 0) {
      $type = NotificationType::CASHFLOW_WARNING;
      $exists = Notification::where('user_id', $userId)
      ->where('type', $type)
      ->where('created_at', '>', Carbon::now()->subDay())
      ->exists();

      if (!$exists) {
        $this->create(
          $userId,
          $type,
          'Peringatan Arus Kas',
          "Saldo total Anda (Rp " . number_format($totalBalance, 0, ',', '.') .
          ") diperkirakan tidak cukup untuk 7 hari ke depan (perkiraan kebutuhan: Rp " .
          number_format($estimatedNeeded, 0, ',', '.') . ").",
          ['estimated_needed' => round($estimatedNeeded, 2)]
        );
      }
    }
  }
}