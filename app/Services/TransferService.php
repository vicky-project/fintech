<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Transfer;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Traits\HasUserCache;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Auth\Authenticatable;

class TransferService
{
  use HasUserCache;

  protected WalletService $walletService;
  protected int $cacheTtl = 3600;

  public function __construct(WalletService $walletService) {
    $this->walletService = $walletService;
  }

  /**
  * Get paginated transfers for user (cached).
  */
  public function getTransfers(Authenticatable $user, array $filters, int $perPage = 20): array
  {
    $filterHash = md5(json_encode($filters));
    $page = request('page', 1);
    $suffix = "transfers_f_{$filterHash}_p{$page}_per{$perPage}";

    return $this->rememberForUser($user->id, $suffix, $this->cacheTtl, function () use ($user, $filters, $perPage) {
      $query = Transfer::with(['fromWallet', 'toWallet'])
      ->where(function ($q) use ($user) {
        $q->whereHas('fromWallet', fn($q) => $q->where('user_id', $user->id))
        ->orWhereHas('toWallet', fn($q) => $q->where('user_id', $user->id));
      })
      ->orderBy('transfer_date', 'desc')
      ->orderBy('id', 'desc');

      if (!empty($filters['wallet_id'])) {
        $walletId = $filters['wallet_id'];
        $query->where(function ($q) use ($walletId) {
          $q->where('from_wallet_id', $walletId)
          ->orWhere('to_wallet_id', $walletId);
        });
      }

      $transfers = $query->paginate($perPage);
      $transformed = $transfers->through(fn($t) => $this->formatTransferData($t));

      return [
        'data' => $transformed,
        'pagination' => [
          'current_page' => $transfers->currentPage(),
          'last_page' => $transfers->lastPage(),
          'per_page' => $transfers->perPage(),
          'total' => $transfers->total(),
        ],
      ];
    });
  }

  /**
  * Get trashed transfers (soft deleted) for user.
  */
  public function getTrashedTransfers(Authenticatable $user,
    int $perPage = 20): array
  {
    $page = request('page',
      1);
    $suffix = "trashed_transfers_p{$page}_per{$perPage}";

    return $this->rememberForUser($user->id,
      $suffix,
      $this->cacheTtl,
      function () use ($user, $perPage) {
        $query = Transfer::onlyTrashed()
        ->with(['fromWallet', 'toWallet'])
        ->where(function ($q) use ($user) {
          $q->whereHas('fromWallet', fn($q) => $q->where('user_id', $user->id))
          ->orWhereHas('toWallet', fn($q) => $q->where('user_id', $user->id));
        })
        ->orderBy('deleted_at', 'desc');

        $transfers = $query->paginate($perPage);
        $transformed = $transfers->through(fn($t) => $this->formatTrashedTransferData($t));

        return [
          'data' => $transformed,
          'pagination' => [
            'current_page' => $transfers->currentPage(),
            'last_page' => $transfers->lastPage(),
            'per_page' => $transfers->perPage(),
            'total' => $transfers->total(),
          ],
        ];
      });
  }

  /**
  * Create a new transfer.
  */
  public function createTransfer(Authenticatable $user,
    array $data): Transfer
  {
    $fromWallet = Wallet::findOrFail($data['from_wallet_id']);
    $toWallet = Wallet::findOrFail($data['to_wallet_id']);

    $this->ensureUserOwnsWallet($user,
      $fromWallet);
    $this->ensureUserOwnsWallet($user,
      $toWallet);

    $amount = Money::of($data['amount'],
      $fromWallet->currency);

    if ($fromWallet->balance->isLessThan($amount)) {
      throw new \Exception('Saldo dompet asal tidak mencukupi.');
    }

    $transfer = DB::transaction(function () use ($fromWallet, $toWallet, $data, $amount) {
      $transfer = new Transfer($data);
      $transfer->amount = $amount;
      $transfer->save();

      $fromWallet->withdraw($amount);
      $toWallet->deposit($amount);

      return $transfer;
    });

    $this->clearUserCache($user->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);
    return $transfer;
  }

  /**
  * Update an existing transfer.
  */
  public function updateTransfer(Authenticatable $user, Transfer $transfer, array $data): Transfer
  {
    $this->ensureUserOwnsTransfer($user, $transfer);

    $fromWallet = $transfer->fromWallet;
    $toWallet = $transfer->toWallet;

    if (isset($data['from_wallet_id']) && $data['from_wallet_id'] != $fromWallet->id) {
      throw new \Exception('Dompet asal tidak dapat diubah.');
    }
    if (isset($data['to_wallet_id']) && $data['to_wallet_id'] != $toWallet->id) {
      throw new \Exception('Dompet tujuan tidak dapat diubah.');
    }

    $oldAmount = $transfer->amount;
    $newAmount = isset($data['amount'])
    ? Money::of($data['amount'], $fromWallet->currency)
    : $oldAmount;

    DB::transaction(function () use ($transfer, $fromWallet, $toWallet, $data, $oldAmount, $newAmount) {
      // Reverse old effect
      $fromWallet->deposit($oldAmount);
      $toWallet->withdraw($oldAmount);

      // Update transfer
      $updatable = array_intersect_key($data, array_flip(['transfer_date', 'description', 'amount']));
      $transfer->fill($updatable);
      if (isset($updatable['amount'])) {
        $transfer->amount = $newAmount;
      }
      $transfer->save();

      // Apply new effect
      $fromWallet->withdraw($newAmount);
      $toWallet->deposit($newAmount);
    });

    $this->clearUserCache($user->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);
    return $transfer->fresh();
  }

  /**
  * Soft delete transfer.
  */
  public function deleteTransfer(Authenticatable $user,
    Transfer $transfer): void
  {
    $this->ensureUserOwnsTransfer($user,
      $transfer);

    DB::transaction(function () use ($transfer) {
      $transfer->fromWallet->deposit($transfer->amount);
      $transfer->toWallet->withdraw($transfer->amount);
      $transfer->delete();
    });

    $this->clearUserCache($user->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);
  }

  /**
  * Restore a soft-deleted transfer.
  */
  public function restoreTransfer(Authenticatable $user,
    int $transferId): void
  {
    $transfer = Transfer::onlyTrashed()->findOrFail($transferId);
    $this->ensureUserOwnsTransfer($user,
      $transfer);

    DB::transaction(function () use ($transfer) {
      $transfer->fromWallet->withdraw($transfer->amount);
      $transfer->toWallet->deposit($transfer->amount);
      $transfer->restore();
    });

    $this->clearUserCache($user->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);
  }

  /**
  * Permanently delete a transfer.
  */
  public function forceDeleteTransfer(Authenticatable $user,
    int $transferId): void
  {
    $transfer = Transfer::withTrashed()->findOrFail($transferId);
    $this->ensureUserOwnsTransfer($user,
      $transfer);
    $transfer->forceDelete();

    $this->clearUserCache($user->id);
    InsightService::clearCache($user->id);
    ReportService::clearReportCaches($user->id);
  }

  // ─── HELPERS ───────────────────────────────────────────

  protected function formatTransferData(Transfer $transfer): array
  {
    return [
      'id' => $transfer->id,
      'from_wallet' => [
        'id' => $transfer->fromWallet->id,
        'name' => $transfer->fromWallet->name,
        'currency' => $transfer->fromWallet->currency,
      ],
      'to_wallet' => [
        'id' => $transfer->toWallet->id,
        'name' => $transfer->toWallet->name,
        'currency' => $transfer->toWallet->currency,
      ],
      'amount' => $transfer->getAmountFloat(),
      'formatted_amount' => $transfer->getFormattedAmount(),
      'transfer_date' => $transfer->transfer_date->toDateString(),
      'description' => $transfer->description,
    ];
  }

  protected function formatTrashedTransferData(Transfer $transfer): array
  {
    return [
      'id' => $transfer->id,
      'from_wallet' => ['id' => $transfer->fromWallet->id, 'name' => $transfer->fromWallet->name],
      'to_wallet' => ['id' => $transfer->toWallet->id, 'name' => $transfer->toWallet->name],
      'amount' => $transfer->getAmountFloat(),
      'formatted_amount' => $transfer->getFormattedAmount(),
      'transfer_date' => $transfer->transfer_date->toDateString(),
      'description' => $transfer->description,
      'deleted_at' => $transfer->deleted_at->toDateTimeString(),
    ];
  }

  protected function ensureUserOwnsWallet(Authenticatable $user,
    Wallet $wallet): void
  {
    if ($wallet->user_id !== $user->id) {
      abort(403, 'Unauthorized');
    }
  }

  protected function ensureUserOwnsTransfer(Authenticatable $user, Transfer $transfer): void
  {
    if ($transfer->fromWallet->user_id !== $user->id && $transfer->toWallet->user_id !== $user->id) {
      abort(403, 'Unauthorized');
    }
  }

  // ─── Trait Override (opsional) ──────────────────────

  protected function knownUserCacheSuffixes(int $userId): array
  {
    return [
      'wallets',
      'budgets',
      'insights',
      'transfers',
    ];
  }
}