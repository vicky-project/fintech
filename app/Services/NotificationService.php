<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Notification;
use Modules\FinTech\Models\Budget;
use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\UserSetting;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Enums\NotificationType;
use Modules\FinTech\Traits\HasUserCache;
use Modules\Telegram\Models\TelegramUser;
use Modules\Telegram\Services\Support\TelegramApi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NotificationService
{
  use HasUserCache;

  protected int $cacheTtl = 3600;

  /**
  * Clear semua cache notifikasi user tertentu.
  */
  public static function clearNotificationCaches(int $userId): void
  {
    app(static::class)->clearUserCache($userId);
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

    $this->clearUserCache($userId);
    $this->sendToTelegramIfEnabled($userId, $title, $message);

    return $notification;
  }

  protected function sendToTelegramIfEnabled(int $userId, string $title, string $message): void
  {
    $settings = UserSetting::where('user_id', $userId)->first();
    if (!$settings) return;

    $prefs = $settings->preferences ?? [];
    if (!($prefs['notification_telegram'] ?? false)) return;

    $user = TelegramUser::find($userId);
    if (!$user) return;

    /** @var TelegramApi $telegramApi */
    $telegramApi = app(TelegramApi::class);
    $telegramApi->sendMessage(
      chatId: $user->telegram_id,
      text: "🔔 *{$title}*\n\n{$message}",
      parseMode: 'Markdown'
    );
  }

  /**
  * Mengambil notifikasi user (belum dibaca dulu, baru yang sudah dibaca).
  */
  public function getForUser(int $userId, int $limit = 50): array
  {
    $suffix = "notifications_limit_{$limit}";

    return $this->rememberForUser($userId, $suffix, $this->cacheTtl, function () use ($userId, $limit) {
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
      $this->clearUserCache($userId);
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
    $this->clearUserCache($userId);
  }

  /**
  * Menghitung jumlah notifikasi belum dibaca.
  */
  public function getUnreadCount(int $userId): int
  {
    $suffix = 'notifications_unread_count';

    return $this->rememberForUser($userId, $suffix, 300, function () use ($userId) {
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

  protected function knownUserCacheSuffixes(int $userId): array
  {
    return [
      'notifications_unread_count',
    ];
  }
}