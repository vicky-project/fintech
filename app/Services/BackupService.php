<?php

namespace Modules\FinTech\Services;

use Modules\Telegram\Models\TelegramUser;
use Modules\FinTech\Models\ {
  Wallet,
  Transaction,
  Category,
  Budget,
  Transfer,
  BankStatement,
  StatementTransaction,
  Notification,
  UserSetting
};
use Illuminate\Support\Facades\DB;

class BackupService
{
  /**
  * Ekspor seluruh data milik user menjadi string JSON (terkompresi gzip).
  */
  public function export(TelegramUser $user): string
  {
    // Kategori yang digunakan user (dari transaksi, budget, statement)
    $catIds = $this->getUsedCategoryIds($user);
    $categories = Category::whereIn('id', $catIds)->get()->toArray();

    $data = [
      'version' => '1.1',
      'user_telegram_id' => $user->telegram_id,
      'created_at' => now()->toIso8601String(),
      'data' => [
        'categories' => $categories,
        'wallets' => Wallet::where('user_id', $user->id)->get()->toArray(),
        'user_settings' => UserSetting::where('user_id', $user->id)->get()->toArray(),
        'transactions' => $this->chunkedExport(
          Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
        ),
        'budgets' => Budget::where('user_id', $user->id)->get()->toArray(),
        'transfers' => Transfer::whereHas('fromWallet', fn($q) => $q->where('user_id', $user->id))
        ->orWhereHas('toWallet', fn($q) => $q->where('user_id', $user->id))
        ->get()->toArray(),
        'bank_statements' => BankStatement::where('user_id', $user->id)->get()->toArray(),
        'statement_transactions' => StatementTransaction::whereHas('statement', fn($q) => $q->where('user_id', $user->id))->get()->toArray(),
        'notifications' => Notification::where('user_id', $user->id)->get()->toArray(),
      ]
    ];

    $json = json_encode($data, JSON_PRETTY_PRINT);
    // Kompresi untuk mengurangi ukuran (opsional, tapi sangat disarankan)
    return gzencode($json, 9);
  }

  public function import(TelegramUser $user, string $content): void
  {
    $decoded = @gzdecode($content); // coba decode gzip
    if ($decoded === false) {
      $decoded = $content; // bukan gzip, gunakan apa adanya
    }

    $backup = json_decode($decoded, true);
    if (!$backup) {
      throw new \Exception('File backup tidak valid (format JSON rusak).');
    }
    if (($backup['user_telegram_id'] ?? null) != $user->telegram_id) {
      throw new \Exception('File ini bukan backup milik akun Telegram Anda.');
    }

    DB::transaction(function () use ($user, $backup) {
      // ... sisa kode restore seperti sebelumnya
    });
  }

  /**
  * Kumpulkan ID kategori yang benar-benar digunakan user.
  */
  private function getUsedCategoryIds(TelegramUser $user): array
  {
    return Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
    ->pluck('category_id')
    ->merge(Budget::where('user_id', $user->id)->pluck('category_id'))
    ->merge(StatementTransaction::whereHas('statement', fn($q) => $q->where('user_id', $user->id))->pluck('category_id'))
    ->unique()
    ->filter()
    ->values()
    ->toArray();
  }

  /**
  * Ekspor dengan chunk untuk menghindari memory overload.
  */
  private function chunkedExport($query): array
  {
    $results = [];
    $query->lazyById(1000)->each(fn($record) => $results[] = $record->toArray());
    return $results;
  }
}