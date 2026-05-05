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

  public function __construct(
    protected BudgetService $budgetService,
    protected InsightService $insightService
  ) {}

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
  * Sekarang menggunakan BudgetService sebagai sumber data.
  */
  public function checkBudgetWarnings(int $userId): void
  {
    $budgets = $this->budgetService->getBudgets($userId);

    foreach ($budgets as $budget) {
      $percentage = $budget['percentage'];

      if ($percentage >= 80 && $percentage < 100) {
        $this->createIfNotExists(
          $userId,
          NotificationType::BUDGET_WARNING,
          'Budget Hampir Habis',
          "Budget {$budget['category']['name']} telah mencapai {$percentage}% (Rp " .
          number_format($budget['current_spending'], 0, ',', '.') . " dari Rp " .
          number_format($budget['amount'], 0, ',', '.') . ").",
          ['budget_id' => $budget['id'], 'category_id' => $budget['category']['id']]
        );
      }

      if ($percentage >= 100) {
        $this->createIfNotExists(
          $userId,
          NotificationType::BUDGET_WARNING,
          'Budget Terlampaui',
          "Budget {$budget['category']['name']} telah terlampaui ({$percentage}%). Segera kurangi pengeluaran.",
          ['budget_id' => $budget['id'], 'category_id' => $budget['category']['id']]
        );
      }
    }
  }

  /**
  * Proyeksi arus kas 7 hari ke depan.
  * Sekarang menggunakan InsightService sebagai sumber data.
  */
  public function checkCashflowProjection(int $userId): void
  {
    $projection = $this->insightService->getCashflowProjection($userId, 7);

    if (!$projection['sufficient'] && $projection['estimated_needed'] > 0) {
      $this->createIfNotExists(
        $userId,
        NotificationType::CASHFLOW_WARNING,
        'Peringatan Arus Kas',
        "Saldo total Anda (Rp " . number_format($projection['balance'], 0, ',', '.') .
        ") diperkirakan tidak cukup untuk 7 hari ke depan (perkiraan kebutuhan: Rp " .
        number_format($projection['estimated_needed'], 0, ',', '.') . ").",
        ['estimated_needed' => $projection['estimated_needed']]
      );
    }
  }

  /**
  * Helper: buat notifikasi jika belum ada dalam 24 jam.
  */
  protected function createIfNotExists(int $userId, NotificationType $type, string $title, string $message, array $data = []): void
  {
    $query = Notification::where('user_id', $userId)
    ->where('type', $type)
    ->where('created_at', '>', Carbon::now()->subDay());

    // Tambahkan filter spesifik berdasarkan tipe
    if ($type === NotificationType::BUDGET_WARNING && isset($data['budget_id'])) {
      $query->where('data->budget_id', $data['budget_id']);
    }

    if (!$query->exists()) {
      $this->create($userId, $type, $title, $message, $data);
    }
  }

  protected function knownUserCacheSuffixes(int $userId): array
  {
    return [
      'notifications_unread_count',
    ];
  }
}