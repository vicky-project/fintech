<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Models\Category;
use Modules\FinTech\Enums\TransactionType;
use Modules\FinTech\Enums\CategoryType;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class TransactionService
{
  protected WalletService $walletService;
  protected int $cacheTtl = 3600; // 1 hour

  public function __construct(WalletService $walletService) {
    $this->walletService = $walletService;
  }

  /**
  * Get paginated transactions with summary (cached per user & filters).
  *
  * @param Authenticatable $user
  * @param array $filters
  * @param int $perPage
  * @return array
  */
  public function getTransactions(Authenticatable $user, array $filters, int $perPage = 20): array
  {
    $cacheKey = $this->generateTransactionsCacheKey($user->id, $filters, $perPage);
    $tags = $this->getTransactionTags($user->id);

    return Cache::tags($tags)->remember($cacheKey, $this->cacheTtl, function () use ($user, $filters, $perPage) {
      $query = $this->buildBaseQuery($user, $filters);

      // Clone for summary (without pagination)
      $summaryQuery = clone $query;

      // Calculate summary
      $totalCount = $summaryQuery->count();
      $totalIncome = (clone $summaryQuery)
      ->where('type', TransactionType::INCOME->value)
      ->sum(DB::raw('amount / 100'));
      $totalExpense = (clone $summaryQuery)
      ->where('type', TransactionType::EXPENSE->value)
      ->sum(DB::raw('amount / 100'));

      // Get paginated results
      $transactions = $query->orderBy('transaction_date', 'desc')
      ->orderBy('id', 'desc')
      ->paginate($perPage);

      // Transform data
      $transformed = $transactions->through(fn($trx) => $this->formatTransactionData($trx));

      return [
        'data' => $transformed,
        'summary' => [
          'total' => $totalCount,
          'income' => (float) $totalIncome,
          'expense' => (float) $totalExpense,
        ],
        'pagination' => [
          'current_page' => $transactions->currentPage(),
          'last_page' => $transactions->lastPage(),
          'per_page' => $transactions->perPage(),
          'total' => $transactions->total(),
        ],
      ];
    });
  }

  /**
  * Get single transaction detail (cached).
  *
  * @param Authenticatable $user
  * @param Transaction $transaction
  * @return array
  * @throws \Symfony\Component\HttpKernel\Exception\HttpException
  */
  public function getTransactionDetail(Authenticatable $user, Transaction $transaction): array
  {
    $this->ensureUserOwnsTransaction($user, $transaction);

    $cacheKey = "transaction_detail_{$transaction->id}";
    $tags = ['transaction_details'];

    return Cache::tags($tags)->remember($cacheKey, $this->cacheTtl, function () use ($transaction) {
      return [
        'id' => $transaction->id,
        'type' => $transaction->type->value,
        'type_label' => $transaction->type->label(),
        'category' => [
          'id' => $transaction->category->id,
          'name' => $transaction->category->name,
        ],
        'amount' => $transaction->getAmountFloat(),
        'formatted_amount' => $transaction->getFormattedAmount(),
        'transaction_date' => $transaction->transaction_date->toDateString(),
        'wallet' => [
          'id' => $transaction->wallet->id,
          'name' => $transaction->wallet->name,
        ],
        'description' => $transaction->description,
        'metadata' => $transaction->metadata,
      ];
    });
  }

  /**
  * Create a new transaction.
  *
  * @param Authenticatable $user
  * @param array $data
  * @return Transaction
  * @throws \Exception
  */
  public function createTransaction(Authenticatable $user, array $data): Transaction
  {
    $wallet = Wallet::findOrFail($data['wallet_id']);
    $this->ensureUserOwnsWallet($user, $wallet);

    $amount = Money::of($data['amount'], $wallet->currency);

    $transaction = DB::transaction(function () use ($wallet, $data, $amount) {
      $transaction = new Transaction($data);
      $transaction->amount = $amount;
      $transaction->save();

      if ($data['type'] === TransactionType::INCOME->value) {
        $wallet->deposit($amount);
      } elseif ($data['type'] === TransactionType::EXPENSE->value) {
        $wallet->withdraw($amount);
      }

      return $transaction;
    });

    $this->clearTransactionCaches($user->id,
      $wallet->id,
      $transaction->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);
    return $transaction;
  }

  /**
  * Update an existing transaction.
  *
  * @param Authenticatable $user
  * @param Transaction $transaction
  * @param array $data
  * @return Transaction
  * @throws \Exception
  */
  public function updateTransaction(Authenticatable $user,
    Transaction $transaction,
    array $data): Transaction
  {
    $this->ensureUserOwnsTransaction($user,
      $transaction);
    $wallet = $transaction->wallet;

    // Prevent wallet change
    if (isset($data['wallet_id']) && $data['wallet_id'] != $wallet->id) {
      throw new \Exception('Dompet tidak dapat diubah.');
    }

    $newAmount = Money::of($data['amount'], $wallet->currency);
    $oldAmount = $transaction->amount;
    $oldType = $transaction->type;

    DB::transaction(function () use ($transaction, $wallet, $data, $newAmount, $oldAmount, $oldType) {
      // Reverse old transaction effect
      if ($oldType === TransactionType::INCOME) {
        $wallet->withdraw($oldAmount);
      } elseif ($oldType === TransactionType::EXPENSE) {
        $wallet->deposit($oldAmount);
      }

      // Update transaction
      $transaction->fill($data);
      $transaction->amount = $newAmount;
      $transaction->save();

      // Apply new effect
      if ($transaction->type === TransactionType::INCOME) {
        $wallet->deposit($newAmount);
      } elseif ($transaction->type === TransactionType::EXPENSE) {
        $wallet->withdraw($newAmount);
      }
    });

    $this->clearTransactionCaches($user->id,
      $wallet->id,
      $transaction->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);
    return $transaction->fresh();
  }

  /**
  * Soft delete transaction (move to trash).
  *
  * @param Authenticatable $user
  * @param Transaction $transaction
  * @return void
  * @throws \Exception
  */
  public function deleteTransaction(Authenticatable $user,
    Transaction $transaction): void
  {
    $this->ensureUserOwnsTransaction($user,
      $transaction);
    $wallet = $transaction->wallet;

    DB::transaction(function () use ($transaction, $wallet) {
      $amount = $transaction->amount;

      if ($transaction->type === TransactionType::INCOME) {
        $wallet->withdraw($amount);
      } elseif ($transaction->type === TransactionType::EXPENSE) {
        $wallet->deposit($amount);
      }

      $transaction->delete();
    });

    $this->clearTransactionCaches($user->id,
      $wallet->id,
      $transaction->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);
  }

  /**
  * Restore a soft-deleted transaction.
  *
  * @param Authenticatable $user
  * @param int $transactionId
  * @return void
  * @throws \Exception
  */
  public function restoreTransaction(Authenticatable $user,
    int $transactionId): void
  {
    $transaction = Transaction::onlyTrashed()->findOrFail($transactionId);
    $this->ensureUserOwnsTransaction($user,
      $transaction);
    $wallet = $transaction->wallet;

    DB::transaction(function () use ($transaction, $wallet) {
      $amount = $transaction->amount;

      if ($transaction->type === TransactionType::INCOME) {
        $wallet->deposit($amount);
      } elseif ($transaction->type === TransactionType::EXPENSE) {
        $wallet->withdraw($amount);
      }

      $transaction->restore();
    });

    $this->clearTransactionCaches($user->id,
      $wallet->id,
      $transaction->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);
  }

  /**
  * Permanently delete a transaction.
  *
  * @param Authenticatable $user
  * @param int $transactionId
  * @return void
  * @throws \Exception
  */
  public function forceDeleteTransaction(Authenticatable $user,
    int $transactionId): void
  {
    $transaction = Transaction::withTrashed()->findOrFail($transactionId);
    $this->ensureUserOwnsTransaction($user,
      $transaction);
    $wallet = $transaction->wallet;

    $transaction->forceDelete();

    $this->clearTransactionCaches($user->id,
      $wallet->id,
      $transaction->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);
  }

  /**
  * Get trashed (soft-deleted) transactions for a user.
  *
  * @param Authenticatable $user
  * @param int $perPage
  * @return array
  */
  public function getTrashedTransactions(Authenticatable $user,
    int $perPage = 20): array
  {
    $cacheKey = "user_{$user->id}_trashed_transactions_page_" . request('page',
      1) . "_per_{$perPage}";
    $tags = $this->getTransactionTags($user->id);

    return Cache::tags($tags)->remember($cacheKey,
      $this->cacheTtl,
      function () use ($user, $perPage) {
        $query = Transaction::onlyTrashed()
        ->with(['wallet', 'category'])
        ->whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
        ->orderBy('deleted_at', 'desc');

        $transactions = $query->paginate($perPage);

        $transformed = $transactions->through(fn($trx) => [
          'id' => $trx->id,
          'type' => $trx->type->value,
          'type_label' => $trx->type->label(),
          'category' => [
            'id' => $trx->category->id,
            'name' => $trx->category->name,
            'icon' => $trx->category->icon,
            'color' => $trx->category->color,
          ],
          'description' => $trx->description,
          'amount' => $trx->getAmountFloat(),
          'formatted_amount' => $trx->getFormattedAmount(),
          'transaction_date' => $trx->transaction_date->toDateString(),
          'deleted_at' => $trx->deleted_at->toDateTimeString(),
          'wallet' => [
            'id' => $trx->wallet->id,
            'name' => $trx->wallet->name,
          ],
        ]);

        return [
          'data' => $transformed,
          'pagination' => [
            'current_page' => $transactions->currentPage(),
            'last_page' => $transactions->lastPage(),
            'per_page' => $transactions->perPage(),
            'total' => $transactions->total(),
          ],
        ];
      });
  }

  // ------------------------------------------------------------------------
  // Helper methods
  // ------------------------------------------------------------------------

  /**
  * Build base query with filters.
  */
  protected function buildBaseQuery(Authenticatable $user,
    array $filters): \Illuminate\Database\Eloquent\Builder
  {
    $query = Transaction::with(['wallet',
      'category'])
    ->whereHas('wallet',
      fn($q) => $q->where('user_id', $user->id));

    if (!empty($filters['wallet_id'])) {
      $query->where('wallet_id', $filters['wallet_id']);
    }
    if (!empty($filters['type'])) {
      $query->where('type', $filters['type']);
    }
    if (!empty($filters['category_id'])) {
      $query->where('category_id', $filters['category_id']);
    }
    if (!empty($filters['month'])) {
      $query->whereYear('transaction_date', substr($filters['month'], 0, 4))
      ->whereMonth('transaction_date', substr($filters['month'], 5, 2));
    }

    return $query;
  }

  /**
  * Format a single transaction for API response.
  */
  protected function formatTransactionData(Transaction $trx): array
  {
    return [
      'id' => $trx->id,
      'type' => $trx->type->value,
      'type_label' => $trx->type->label(),
      'type_icon' => $trx->type->icon(),
      'type_sign' => $trx->type->sign(),
      'category' => [
        'id' => $trx->category->id,
        'name' => $trx->category->name,
        'icon' => $trx->category->icon,
        'color' => $trx->category->color,
      ],
      'description' => $trx->description,
      'amount' => $trx->getAmountFloat(),
      'formatted_amount' => $trx->getFormattedAmount(),
      'transaction_date' => $trx->transaction_date->toDateString(),
      'wallet' => [
        'id' => $trx->wallet->id,
        'name' => $trx->wallet->name,
      ],
      'metadata' => $trx->metadata,
    ];
  }

  /**
  * Generate cache key for transactions list.
  */
  protected function generateTransactionsCacheKey(int $userId, array $filters, int $perPage): string
  {
    ksort($filters);
    $filterHash = md5(json_encode($filters));
    $page = request('page', 1);
    return "transactions_user_{$userId}_filter_{$filterHash}_page_{$page}_per_{$perPage}";
  }

  /**
  * Get cache tags for a user's transactions.
  */
  protected function getTransactionTags(int $userId): array
  {
    return ['transactions_user_' . $userId];
  }

  /**
  * Clear all caches related to transactions for a user and optionally specific wallet/transaction.
  */
  protected function clearTransactionCaches(int $userId, ?int $walletId = null, ?int $transactionId = null): void
  {
    // Clear user's transaction list cache (all pages)
    Cache::tags($this->getTransactionTags($userId))->flush();

    // Clear trashed transactions cache
    Cache::tags($this->getTransactionTags($userId))->flush(); // same tags

    // Clear single transaction detail cache
    if ($transactionId) {
      Cache::tags(['transaction_details'])->forget("transaction_detail_{$transactionId}");
    }

    // Clear wallet cache (balances and wallet lists)
    if ($walletId) {
      $this->walletService->clearWalletCaches($walletId, $userId);
    }
  }

  /**
  * Ensure user owns the wallet.
  */
  protected function ensureUserOwnsWallet(Authenticatable $user, Wallet $wallet): void
  {
    if ($wallet->user_id !== $user->id) {
      abort(403, 'Unauthorized');
    }
  }

  /**
  * Ensure user owns the transaction (via wallet).
  */
  protected function ensureUserOwnsTransaction(Authenticatable $user, Transaction $transaction): void
  {
    if ($transaction->wallet->user_id !== $user->id) {
      abort(403, 'Unauthorized');
    }
  }
}