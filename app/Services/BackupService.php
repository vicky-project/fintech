<?php

namespace Modules\FinTech\Services;

use Modules\Telegram\Models\TelegramUser;
use Modules\FinTech\Models;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackupService
{
  /**
  * Ekspor seluruh data user menjadi string JSON terkompresi (gzip).
  */
  public function export(TelegramUser $user, ?string $password = null): string
  {
    $catIds = $this->getUsedCategoryIds($user);

    // Kategori: export mentah semua kolom
    $categories = $this->exportTable(
      'fintech_categories',
      Models\Category::whereIn('id', $catIds)
    );

    // Wallet: export mentah, tanpa eager load currencyDetails
    $wallets = $this->exportTable(
      'fintech_wallets',
      Models\Wallet::where('user_id', $user->id)->without('currencyDetails')
    );

    // Transactions: export mentah
    $transactions = $this->exportTable(
      'fintech_transactions',
      Models\Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
    );

    // Transfers: export mentah
    $transfers = $this->exportTable(
      'fintech_transfers',
      Models\Transfer::whereHas('fromWallet', fn($q) => $q->where('user_id', $user->id))
      ->orWhereHas('toWallet', fn($q) => $q->where('user_id', $user->id))
    );

    // Budgets: export mentah
    $budgets = $this->exportTable(
      'fintech_budgets',
      Models\Budget::where('user_id', $user->id)
    );

    // Bank Statements
    $bankStatements = $this->exportTable(
      'fintech_bank_statements',
      Models\BankStatement::where('user_id', $user->id)
    );

    // Statement Transactions
    $statementTransactions = $this->exportTable(
      'fintech_statement_transactions',
      Models\StatementTransaction::whereHas('statement', fn($q) => $q->where('user_id', $user->id))
    );

    // Notifications
    $notifications = $this->exportTable(
      'fintech_notifications',
      Models\Notification::where('user_id', $user->id)
    );

    // User Settings
    $userSettings = $this->exportTable(
      'fintech_user_settings',
      Models\UserSetting::where('user_id', $user->id)
    );

    $data = [
      'version' => '1.1',
      'user_telegram_id' => $user->telegram_id,
      'created_at' => now()->toIso8601String(),
      'data' => [
        'categories' => $categories,
        'wallets' => $wallets,
        'user_settings' => $userSettings,
        'transactions' => $transactions,
        'budgets' => $budgets,
        'transfers' => $transfers,
        'bank_statements' => $bankStatements,
        'statement_transactions' => $statementTransactions,
        'notifications' => $notifications,
      ]
    ];

    $json = json_encode($data, JSON_PRETTY_PRINT);
    $compressed = gzencode($json, 9);

    if ($password) {
      $encrypted = $this->encrypt($compressed, $password);
      return json_encode([
        'version' => '2.0',
        'encrypted' => true,
        'data' => base64_encode($encrypted)
      ]);
    }

    return $compressed;
  }

  /**
  * Impor data dari file backup ke database user.
  */
  public function import(TelegramUser $user, string $content, ?string $password = null): void
  {
    $decoded = json_decode($content, true);

    if (is_array($decoded) && isset($decoded['encrypted']) && $decoded['encrypted'] === true) {
      if (!$password) {
        throw new \Exception('File backup ini terenkripsi. Mohon masukan password untuk membukanya.');
      }

      $chipertext = base64_decode($decoded['data']);
      $content = $this->decrypt($chipertext, $password);
    } else {
      $content = @gzdecode($content) ?: $content;
    }

    $backup = json_decode($content, true);
    if (!$backup) {
      throw new \Exception('File backup tidak valid (format JSON rusak).');
    }
    if (($backup['user_telegram_id'] ?? null) != $user->telegram_id) {
      throw new \Exception('File ini bukan backup milik akun Telegram Anda.');
    }

    DB::transaction(function () use ($user, $backup) {
      $this->clearUserData($user);
      DB::statement('SET FOREIGN_KEY_CHECKS=0');

      $catMap = $this->restoreCategories($backup['data']['categories']);
      $walletMap = $this->bulkInsertByUuid('fintech_wallets', $backup['data']['wallets'], function(&$row) use ($user) {
        unset($row['id']);
        $row['user_id'] = $user->id;
      });

      $this->bulkInsert('fintech_user_settings', $backup['data']['user_settings'], function(&$row) use ($user, $walletMap) {
        unset($row['id']);
        $row['user_id'] = $user->id;
        $row['default_wallet_id'] = $row['default_wallet_id'] ? ($walletMap[$row['default_wallet_id']] ?? null) : null;
      });

      $trxMap = $this->bulkInsertByUuid('fintech_transactions', $backup['data']['transactions'], function(&$row) use ($walletMap, $catMap) {
        unset($row['id']);
        $row['wallet_id'] = $walletMap[$row['wallet_id']];
        $row['category_id'] = $catMap[$row['category_id']] ?? null;
      });

      $this->bulkInsert('fintech_budgets', $backup['data']['budgets'], function(&$row) use ($user, $catMap, $walletMap) {
        unset($row['id']);
        $row['user_id'] = $user->id;
        $row['category_id'] = $catMap[$row['category_id']] ?? null;
        $row['wallet_id'] = $row['wallet_id'] ? ($walletMap[$row['wallet_id']] ?? null) : null;
      });

      $stmtMap = $this->bulkInsertByUuid('fintech_bank_statements', $backup['data']['bank_statements'], function(&$row) use ($user, $walletMap) {
        unset($row['id']);
        $row['user_id'] = $user->id;
        $row['wallet_id'] = $row['wallet_id'] ? ($walletMap[$row['wallet_id']] ?? null) : null;
      });

      $this->bulkInsert('fintech_transfers', $backup['data']['transfers'], function(&$row) use ($walletMap) {
        unset($row['id']);
        $row['from_wallet_id'] = $walletMap[$row['from_wallet_id']];
        $row['to_wallet_id'] = $walletMap[$row['to_wallet_id']];
      });

      $this->bulkInsert('fintech_statement_transactions', $backup['data']['statement_transactions'], function(&$row) use ($stmtMap, $catMap) {
        unset($row['id']);
        $row['statement_id'] = $stmtMap[$row['statement_id']];
        $row['category_id'] = $row['category_id'] ? ($catMap[$row['category_id']] ?? null) : null;
      });

      $this->bulkInsert('fintech_notifications', $backup['data']['notifications'], function(&$row) use ($user, $trxMap) {
        unset($row['id']);
        $row['user_id'] = $user->id;
        if (isset($row['data']['transaction_id'])) {
          $newTrxId = $trxMap[$row['data']['transaction_id']] ?? null;
          if ($newTrxId) {
            $row['data']['transaction_id'] = $newTrxId;
          }
        }
      });

      DB::statement('SET FOREIGN_KEY_CHECKS=1');
    });
  }

  // ─── Helper Methods ────────────────────────────────────────

  /**
  * Ekspor tabel dengan nilai mentah (raw) untuk semua kolom, tanpa casting.
  */
  private function exportTable(string $table, $query): array
  {
    $columns = Schema::getColumnListing($table);
    return $query->get()->map(function ($model) use ($columns) {
      $data = [];
      foreach ($columns as $col) {
        $data[$col] = $model->getRawOriginal($col);
      }
      return $data;
    })->toArray();
  }

  private function getUsedCategoryIds(TelegramUser $user): array
  {
    return Models\Transaction::whereHas('wallet', fn($q) => $q->where('user_id',
      $user->id))
    ->pluck('category_id')
    ->merge(Models\Budget::where('user_id',
      $user->id)->pluck('category_id'))
    ->merge(Models\StatementTransaction::whereHas('statement',
      fn($q) => $q->where('user_id', $user->id))->pluck('category_id'))
    ->unique()
    ->filter()
    ->values()
    ->toArray();
  }

  private function clearUserData(TelegramUser $user): void
  {
    Models\Notification::where('user_id', $user->id)->delete();
    Models\StatementTransaction::whereHas('statement', fn($q) => $q->where('user_id',
      $user->id))->delete();
    Models\BankStatement::where('user_id', $user->id)->delete();
    Models\Transfer::whereHas('fromWallet', fn($q) => $q->where('user_id',
      $user->id))->delete();
    Models\Transfer::whereHas('toWallet', fn($q) => $q->where('user_id',
      $user->id))->delete();
    Models\Budget::where('user_id', $user->id)->delete();
    Models\Transaction::whereHas('wallet', fn($q) => $q->where('user_id',
      $user->id))->delete();
    Models\UserSetting::where('user_id', $user->id)->delete();
    Models\Wallet::where('user_id', $user->id)->delete();
  }

  private function restoreCategories(array $categories): array
  {
    $map = [];
    $uuids = array_column($categories, 'uuid');
    $existing = DB::table('fintech_categories')->whereIn('uuid', $uuids)->get()->keyBy('uuid');

    foreach ($categories as $cat) {
      if (isset($existing[$cat['uuid']])) {
        $map[$cat['id']] = $existing[$cat['uuid']]->id;
      } else {
        $newId = DB::table('fintech_categories')->insertGetId([
          'name' => $cat['name'],
          'icon' => $cat['icon'],
          'color' => $cat['color'],
          'type' => $cat['type'],
          'uuid' => $cat['uuid'],
          'parent_id' => null, // diperbaiki nanti
          'is_system' => $cat['is_system'] ?? false,
          'is_active' => $cat['is_active'] ?? true,
          'metadata' => $cat['metadata'],
          'keywords' => $cat['keywords'],
          'created_at' => $cat['created_at'],
          'updated_at' => $cat['updated_at'],
        ]);
        $map[$cat['id']] = $newId;
      }
    }

    // Perbaiki parent_id
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

  /**
  * Enkripsi data dengan AES‑256‑CBC menggunakan password.
  * @return string iv + ciphertext (16 byte pertama adalah IV)
  */
  private function encrypt(string $data, string $password): string
  {
    $iv = random_bytes(16);
    $key = hash('sha256', $password, true);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $iv . $encrypted;
  }

  /**
  * Dekripsi data yang dihasilkan oleh encrypt().
  * @throws \Exception jika password salah atau data rusak
  */
  private function decrypt(string $data, string $password): string
  {
    $iv = substr($data, 0, 16);
    $ciphertext = substr($data, 16);
    $key = hash('sha256', $password, true);
    $plain = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    if ($plain === false) {
      throw new \Exception('Password salah atau file backup rusak.');
    }
    return $plain;
  }
}