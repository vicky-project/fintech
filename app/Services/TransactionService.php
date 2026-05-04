<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Models\Category;
use Modules\FinTech\Enums\TransactionType;
use Modules\FinTech\Traits\HasUserCache;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Auth\Authenticatable;

class TransactionService
{
  use HasUserCache;

  protected WalletService $walletService;
  protected int $cacheTtl = 3600;

  public function __construct(WalletService $walletService) {
    $this->walletService = $walletService;
  }

  // ─── PUBLIC API ────────────────────────────────────────

  public function getTransactions(Authenticatable $user, array $filters, int $perPage = 20): array
  {
    $filterHash = md5(json_encode($filters));
    $page = request('page', 1);
    $suffix = "transactions_f_{$filterHash}_p{$page}_per{$perPage}";

    return $this->rememberForUser($user->id, $suffix, $this->cacheTtl, function () use ($user, $filters, $perPage) {
      $query = $this->buildBaseQuery($user, $filters);
      $summaryQuery = clone $query;

      $totalCount = $summaryQuery->count();
      $totalIncome = (clone $summaryQuery)->where('type', TransactionType::INCOME->value)->sum(DB::raw('amount / 100'));
      $totalExpense = (clone $summaryQuery)->where('type', TransactionType::EXPENSE->value)->sum(DB::raw('amount / 100'));

      $transactions = $query->orderBy('transaction_date', 'desc')
      ->orderBy('id', 'desc')
      ->paginate($perPage);

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

  public function getTransactionDetail(Authenticatable $user, Transaction $transaction): array
  {
    $this->ensureUserOwnsTransaction($user, $transaction);
    $suffix = "transaction_detail_{$transaction->id}";

    return $this->rememberForUser($user->id, $suffix, $this->cacheTtl, function () use ($transaction) {
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

    $this->clearUserCache($user->id);
    $this->clearOtherCaches($user->id);

    return $transaction;
  }

  public function updateTransaction(Authenticatable $user,
    Transaction $transaction,
    array $data): Transaction
  {
    $this->ensureUserOwnsTransaction($user,
      $transaction);
    $wallet = $transaction->wallet;

    if (isset($data['wallet_id']) && $data['wallet_id'] != $wallet->id) {
      throw new \Exception('Dompet tidak dapat diubah.');
    }

    $newAmount = isset($data['amount']) ? Money::of($data['amount'], $wallet->currency) : $transaction->amount;
    $newType = $data['type'] ?? $transaction->type->value;
    $oldAmount = $transaction->amount;
    $oldType = $transaction->type->value;

    $amountChanged = !$oldAmount->isEqualTo($newAmount);
    $typeChanged = $oldType !== $newType;

    DB::transaction(function () use ($transaction, $wallet, $data, $newAmount, $newType, $oldAmount, $oldType, $amountChanged, $typeChanged) {
      if ($amountChanged || $typeChanged) {
        $netEffect = Money::zero($wallet->currency);
        if ($oldType === TransactionType::INCOME->value) {
          $netEffect = $netEffect->minus($oldAmount);
        } else {
          $netEffect = $netEffect->plus($oldAmount);
        }
        if ($newType === TransactionType::INCOME->value) {
          $netEffect = $netEffect->plus($newAmount);
        } else {
          $netEffect = $netEffect->minus($newAmount);
        }

        if ($netEffect->isNegative()) {
          $required = $netEffect->abs();
          if ($wallet->balance->isLessThan($required)) {
            throw new \Exception('Saldo tidak mencukupi untuk perubahan ini.');
          }
          $wallet->withdraw($required);
        } elseif ($netEffect->isPositive()) {
          $wallet->deposit($netEffect);
        }
      }
      $transaction->fill($data);
      $transaction->amount = $newAmount;
      $transaction->save();
    });

    $this->clearUserCache($user->id);
    $this->clearOtherCaches($user->id);

    return $transaction->fresh();
  }

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

    $this->clearUserCache($user->id);
    $this->clearOtherCaches($user->id);
  }

  public function bulkDeleteTransactions(Authenticatable $user,
    int $walletId,
    string $month): int
  {
    $wallet = Wallet::where('user_id',
      $user->id)->findOrFail($walletId);
    [$year, $monthNum] = explode('-',
      $month);

    $transactions = Transaction::where('wallet_id',
      $walletId)
    ->whereYear('transaction_date',
      $year)
    ->whereMonth('transaction_date',
      $monthNum)
    ->get();

    if ($transactions->isEmpty()) {
      return 0;
    }

    DB::transaction(function () use ($transactions, $wallet) {
      foreach ($transactions as $transaction) {
        $amount = $transaction->amount;
        if ($transaction->type === TransactionType::INCOME) {
          $wallet->withdraw($amount);
        } elseif ($transaction->type === TransactionType::EXPENSE) {
          $wallet->deposit($amount);
        }
        $transaction->delete();
      }
    });

    $this->clearUserCache($user->id);
    $this->clearOtherCaches($user->id);

    return $transactions->count();
  }

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

    $this->clearUserCache($user->id);
    $this->clearOtherCaches($user->id);
  }

  public function forceDeleteTransaction(Authenticatable $user,
    int $transactionId): void
  {
    $transaction = Transaction::withTrashed()->findOrFail($transactionId);
    $this->ensureUserOwnsTransaction($user,
      $transaction);
    $transaction->forceDelete();

    $this->clearUserCache($user->id);
    $this->clearOtherCaches($user->id);
  }

  public function getTrashedTransactions(Authenticatable $user,
    int $perPage = 20): array
  {
    $page = request('page',
      1);
    $suffix = "trashed_transactions_p{$page}_per{$perPage}";

    return $this->rememberForUser($user->id,
      $suffix,
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

  // ─── HELPERS ───────────────────────────────────────────

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
    if (!empty($filters['month'])) {
      $query->whereYear('transaction_date', substr($filters['month'], 0, 4))
      ->whereMonth('transaction_date', substr($filters['month'], 5, 2));
    }
    return $query;
  }

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

  protected function ensureUserOwnsWallet(Authenticatable $user, Wallet $wallet): void
  {
    if ($wallet->user_id !== $user->id) {
      abort(403, 'Unauthorized');
    }
  }

  protected function ensureUserOwnsTransaction(Authenticatable $user, Transaction $transaction): void
  {
    if ($transaction->wallet->user_id !== $user->id) {
      abort(403, 'Unauthorized');
    }
  }

  protected function clearOtherCaches(int $userId): void
  {
    InsightService::clearCache($userId);
    ReportService::clearReportCaches($userId);
    BudgetService::clearBudgetCaches($userId);
    NotificationService::clearNotificationCaches($userId);
  }

  // ─── Trait Override (opsional) ──────────────────────

  protected function knownUserCacheSuffixes(int $userId): array
  {
    return [
      'wallets',
      'budgets',
      'insights',
      // tambahkan suffix umum lainnya jika diperlukan
    ];
  }
}