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
  * Ekspor seluruh data user menjadi string JSON terkompresi (gzip).
  */
  public function export(TelegramUser $user): string
  {
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
    return gzencode($json, 9);
  }

  /**
  * Impor data dari file backup ke database user.
  */
  public function import(TelegramUser $user, string $content): void
  {
    $decoded = @gzdecode($content);
    if ($decoded === false) {
      $decoded = $content;
    }

    $backup = json_decode($decoded, true);
    if (!$backup) {
      throw new \Exception('File backup tidak valid (format JSON rusak).');
    }
    if (($backup['user_telegram_id'] ?? null) != $user->telegram_id) {
      throw new \Exception('File ini bukan backup milik akun Telegram Anda.');
    }

    DB::transaction(function () use ($user, $backup) {
      // 1. Hapus semua data user saat ini
      $this->clearUserData($user);

      DB::statement('SET FOREIGN_KEY_CHECKS=0');

      // 2. Restore categories (global dengan pengecekan UUID)
      $catMap = $this->restoreCategories($backup['data']['categories']);

      // 3. Wallets
      $walletMap = $this->bulkInsertByUuid('fintech_wallets', $backup['data']['wallets'], function(&$row) use ($user) {
        unset($row['id']);
        $row['user_id'] = $user->id;
      });

      // 4. User Settings
      $this->bulkInsert('fintech_user_settings', $backup['data']['user_settings'], function(&$row) use ($user, $walletMap) {
        unset($row['id']);
        $row['user_id'] = $user->id;
        $row['default_wallet_id'] = $row['default_wallet_id'] ? ($walletMap[$row['default_wallet_id']] ?? null) : null;
        // Encode JSON field jika ada
        if (isset($row['preferences']) && is_array($row['preferences'])) {
          $row['preferences'] = json_encode($row['preferences']);
        }
      });

      // 5. Transactions
      $trxMap = $this->bulkInsertByUuid('fintech_transactions',
        $backup['data']['transactions'],
        function(&$row) use ($walletMap, $catMap) {
          unset($row['id']);
          $row['wallet_id'] = $walletMap[$row['wallet_id']];
          $row['category_id'] = $catMap[$row['category_id']] ?? null;
          // Encode metadata jika array
          if (isset($row['metadata']) && is_array($row['metadata'])) {
            $row['metadata'] = json_encode($row['metadata']);
          }
        });

      // 6. Budgets
      $this->bulkInsert('fintech_budgets',
        $backup['data']['budgets'],
        function(&$row) use ($user, $catMap, $walletMap) {
          unset($row['id']);
          $row['user_id'] = $user->id;
          $row['category_id'] = $catMap[$row['category_id']] ?? null;
          $row['wallet_id'] = $row['wallet_id'] ? ($walletMap[$row['wallet_id']] ?? null) : null;
        });

      // 7. Bank Statements
      $stmtMap = $this->bulkInsertByUuid('fintech_bank_statements',
        $backup['data']['bank_statements'],
        function(&$row) use ($user, $walletMap) {
          unset($row['id']);
          $row['user_id'] = $user->id;
          $row['wallet_id'] = $row['wallet_id'] ? ($walletMap[$row['wallet_id']] ?? null) : null;
          if (isset($row['meta_data']) && is_array($row['meta_data'])) {
            $row['meta_data'] = json_encode($row['meta_data']);
          }
        });

      // 8. Transfers
      $this->bulkInsert('fintech_transfers',
        $backup['data']['transfers'],
        function(&$row) use ($walletMap) {
          unset($row['id']);
          $row['from_wallet_id'] = $walletMap[$row['from_wallet_id']];
          $row['to_wallet_id'] = $walletMap[$row['to_wallet_id']];
        });

      // 9. Statement Transactions
      $this->bulkInsert('fintech_statement_transactions',
        $backup['data']['statement_transactions'],
        function(&$row) use ($stmtMap, $catMap) {
          unset($row['id']);
          $row['statement_id'] = $stmtMap[$row['statement_id']];
          $row['category_id'] = $row['category_id'] ? ($catMap[$row['category_id']] ?? null) : null;
          if (isset($row['raw_data']) && is_array($row['raw_data'])) {
            $row['raw_data'] = json_encode($row['raw_data']);
          }
        });

      // 10. Notifications
      $this->bulkInsert('fintech_notifications',
        $backup['data']['notifications'],
        function(&$row) use ($user, $trxMap) {
          unset($row['id']);
          $row['user_id'] = $user->id;
          if (isset($row['data']['transaction_id'])) {
            $newTrxId = $trxMap[$row['data']['transaction_id']] ?? null;
            if ($newTrxId) {
              $row['data']['transaction_id'] = $newTrxId;
            }
          }
          if (isset($row['data']) && is_array($row['data'])) {
            $row['data'] = json_encode($row['data']);
          }
        });

      DB::statement('SET FOREIGN_KEY_CHECKS=1');
    });
  }

  // ─── Helper Methods ────────────────────────────────────────

  private function getUsedCategoryIds(TelegramUser $user): array
  {
    return Transaction::whereHas('wallet', fn($q) => $q->where('user_id',
      $user->id))
    ->pluck('category_id')
    ->merge(Budget::where('user_id',
      $user->id)->pluck('category_id'))
    ->merge(StatementTransaction::whereHas('statement',
      fn($q) => $q->where('user_id', $user->id))->pluck('category_id'))
    ->unique()
    ->filter()
    ->values()
    ->toArray();
  }

  private function chunkedExport($query): array
  {
    $results = [];
    $query->lazyById(1000)->each(fn($record) => $results[] = $record->toArray());
    return $results;
  }

  private function clearUserData(TelegramUser $user): void
  {
    Notification::where('user_id', $user->id)->delete();
    StatementTransaction::whereHas('statement', fn($q) => $q->where('user_id',
      $user->id))->delete();
    BankStatement::where('user_id', $user->id)->delete();
    Transfer::whereHas('fromWallet', fn($q) => $q->where('user_id',
      $user->id))->delete();
    Transfer::whereHas('toWallet', fn($q) => $q->where('user_id',
      $user->id))->delete();
    Budget::where('user_id', $user->id)->delete();
    Transaction::whereHas('wallet', fn($q) => $q->where('user_id',
      $user->id))->delete();
    UserSetting::where('user_id', $user->id)->delete();
    Wallet::where('user_id', $user->id)->delete();
  }

  /**
  * Restore kategori global. Jika UUID sudah ada di database, gunakan ID-nya.
  * Jika belum, buat baru. Mengembalikan peta old_id => new_id.
  */
  private function restoreCategories(array $categories): array
  {
    $map = [];
    $uuids = array_column($categories, 'uuid');
    $existing = DB::table('fintech_categories')->whereIn('uuid', $uuids)->get()->keyBy('uuid');

    foreach ($categories as $cat) {
      if (isset($existing[$cat['uuid']])) {
        // Kategori sudah ada, gunakan ID yang ada
        $map[$cat['id']] = $existing[$cat['uuid']]->id;
      } else {
        // Buat baru dengan UUID yang sama
        $newId = DB::table('fintech_categories')->insertGetId([
          'name' => $cat['name'],
          'icon' => $cat['icon'],
          'color' => $cat['color'],
          'type' => $cat['type'],
          'uuid' => $cat['uuid'],
          'parent_id' => null, // akan diperbaiki setelahnya
          'is_system' => $cat['is_system'] ?? false,
          'is_active' => $cat['is_active'] ?? true,
          'metadata' => is_array($cat['metadata'] ?? null) ? json_encode($cat['metadata']) : $cat['metadata'],
          'keywords' => is_array($cat['keywords'] ?? null) ? json_encode($cat['keywords']) : $cat['keywords'],
          'created_at' => $cat['created_at'],
          'updated_at' => $cat['updated_at'],
        ]);
        $map[$cat['id']] = $newId;
      }
    }

    // Perbaiki parent_id menggunakan mapping yang sudah terbentuk
    foreach ($categories as $cat) {
      if (!empty($cat['parent_id'])) {
        $newParent = $map[$cat['parent_id']] ?? null;
        if ($newParent) {
          DB::table('fintech_categories')
          ->where('uuid', $cat['uuid'])
          ->update(['parent_id' => $newParent]);
        }
      }
    }

    return $map;
  }

  /**
  * Bulk insert ke tabel, menghasilkan peta old_id => new_id berdasarkan UUID.
  */
  private function bulkInsertByUuid(string $table, array $rows, callable $callback): array
  {
    $inserts = [];
    foreach ($rows as $row) {
      $callback($row);
      $inserts[] = $row;
    }

    foreach (array_chunk($inserts, 1000) as $chunk) {
      DB::table($table)->insert($chunk);
    }

    // Ambil ID baru berdasarkan UUID
    $uuids = array_column($rows, 'uuid');
    $newRecords = DB::table($table)->whereIn('uuid', $uuids)->get();
    $mapByUuid = [];
    foreach ($newRecords as $rec) {
      $mapByUuid[$rec->uuid] = $rec->id;
    }

    $idMap = [];
    foreach ($rows as $row) {
      $idMap[$row['id']] = $mapByUuid[$row['uuid']] ?? null;
    }

    return $idMap;
  }

  /**
  * Bulk insert tanpa perlu mengembalikan mapping ID (untuk tabel yang tidak dirujuk balik).
  */
  private function bulkInsert(string $table, array $rows, callable $callback): void
  {
    $inserts = [];
    foreach ($rows as $row) {
      $callback($row);
      $inserts[] = $row;
    }

    foreach (array_chunk($inserts, 1000) as $chunk) {
      DB::table($table)->insert($chunk);
    }
  }
}