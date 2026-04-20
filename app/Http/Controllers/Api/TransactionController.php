<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\FinTech\Http\Requests\TransactionRequest;
use Modules\FinTech\Http\Requests\TransferRequest;
use Modules\FinTech\Models\Category;
use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Enums\CategoryType;
use Modules\FinTech\Enums\TransactionType;
use Brick\Money\Money;

class TransactionController extends Controller
{
  /**
  * Display a listing of transactions.
  */
  public function index(): JsonResponse
  {
    $request = request();
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'type' => 'nullable|in:' . implode(',', TransactionType::values()),
      'category_id' => 'nullable|exists:fintech_categories,id',
      'month' => 'nullable|date_format:Y-m',
      'per_page' => 'integer|min:1|max:100'
    ]);

    $query = Transaction::with(['wallet', 'category'])
    ->whereHas('wallet', fn($q) => $q->where('user_id', $request->user()->id));

    if ($walletId = $request->input('wallet_id')) {
      $query->where('wallet_id', $walletId);
    }
    if ($type = $request->input('type')) {
      $query->where('type', $type);
    }
    if ($categoryId = $request->input('category_id')) {
      $query->where('category_id', $categoryId);
    }
    if ($month = $request->input('month')) {
      $query->whereYear('transaction_date', substr($month, 0, 4))
      ->whereMonth('transaction_date', substr($month, 5, 2));
    }

    $transactions = $query->orderBy('transaction_date', 'desc')
    ->orderBy('id', 'desc')
    ->paginate($request->input('per_page', 20));

    $transformed = $transactions->through(fn($trx) => [
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
    ]);

    return response()->json(['success' => true, 'data' => $transformed]);
  }

  /**
  * Store a newly created transaction.
  */
  public function store(TransactionRequest $request): JsonResponse
  {
    $wallet = Wallet::findOrFail($request->wallet_id);

    try {
      $amount = Money::of($request->amount, $wallet->currency);

      DB::transaction(function () use ($wallet, $request, $amount) {
        $transaction = new Transaction($request->validated());
        $transaction->amount = $amount;
        $transaction->save();

        if ($request->type === TransactionType::INCOME->value) {
          $wallet->deposit($amount);
        } elseif ($request->type === TransactionType::EXPENSE->value) {
          $wallet->withdraw($amount);
        }
      });

      return response()->json([
        'success' => true,
        'message' => 'Transaksi berhasil disimpan'
      ],
        201);
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => $e->getMessage()
      ],
        400);
    }
  }

  /**
  * Display the specified transaction.
  */
  public function show(Transaction $transaction): JsonResponse
  {
    if ($transaction->wallet->user_id !== request()->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    return response()->json([
      'success' => true,
      'data' => [
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
        'wallet' => ['id' => $transaction->wallet->id, 'name' => $transaction->wallet->name],
      ]
    ]);
  }

  /**
  * Update the specified transaction.
  */
  public function update(TransactionRequest $request, Transaction $transaction): JsonResponse
  {
    if ($transaction->wallet->user_id !== $request->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    // Validasi tambahan: wallet_id tidak boleh berubah
    if ($request->has('wallet_id') && $request->wallet_id != $transaction->wallet_id) {
      return response()->json([
        'success' => false,
        'message' => 'Dompet tidak dapat diubah.'
      ], 422);
    }

    try {
      DB::transaction(function () use ($request, $transaction) {
        $oldAmount = $transaction->amount;
        $oldType = $transaction->type;
        $wallet = $transaction->wallet;

        // Kembalikan saldo ke kondisi sebelum transaksi
        if ($oldType === TransactionType::INCOME) {
          $wallet->withdraw($oldAmount);
        } elseif ($oldType === TransactionType::EXPENSE) {
          $wallet->deposit($oldAmount);
        }

        // Update data transaksi
        $transaction->fill($request->validated());
        $transaction->amount = Money::of($request->amount, $wallet->currency);
        $transaction->save();

        // Terapkan saldo baru
        if ($transaction->type === TransactionType::INCOME) {
          $wallet->deposit($transaction->amount);
        } elseif ($transaction->type === TransactionType::EXPENSE) {
          $wallet->withdraw($transaction->amount);
        }
      });

      return response()->json([
        'success' => true,
        'message' => 'Transaksi berhasil diperbarui'
      ]);
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => $e->getMessage()
      ],
        400);
    }
  }

  /**
  * Soft delete transaksi.
  */
  public function destroy(Transaction $transaction): JsonResponse
  {
    if ($transaction->wallet->user_id !== request()->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    DB::transaction(function () use ($transaction) {
      $wallet = $transaction->wallet;
      $amount = $transaction->amount;

      // Kembalikan saldo ke kondisi sebelum transaksi
      if ($transaction->type === TransactionType::INCOME) {
        $wallet->withdraw($amount);
      } elseif ($transaction->type === TransactionType::EXPENSE) {
        $wallet->deposit($amount);
      }

      $transaction->delete();
    });

    return response()->json([
      'success' => true,
      'message' => 'Transaksi dipindahkan ke tempat sampah'
    ]);
  }

  /**
  * Menampilkan transaksi yang telah dihapus (trash).
  */
  public function trashed(): JsonResponse
  {
    $request = request();
    $query = Transaction::onlyTrashed()
    ->with(['wallet',
      'category'])
    ->whereHas('wallet',
      fn($q) => $q->where('user_id', $request->user()->id))
    ->orderBy('deleted_at',
      'desc');

    $transactions = $query->paginate($request->input('per_page', 20));

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

    return response()->json([
      'success' => true,
      'data' => $transformed
    ]);
  }

  /**
  * Memulihkan transaksi dari tempat sampah.
  */
  public function restore($id): JsonResponse
  {
    $transaction = Transaction::onlyTrashed()->findOrFail($id);

    if ($transaction->wallet->user_id !== request()->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    DB::transaction(function () use ($transaction) {
      $wallet = $transaction->wallet;
      $amount = $transaction->amount;

      if ($transaction->type === TransactionType::INCOME) {
        $wallet->deposit($amount);
      } elseif ($transaction->type === TransactionType::EXPENSE) {
        $wallet->withdraw($amount);
      }

      $transaction->restore();
    });

    return response()->json([
      'success' => true,
      'message' => 'Transaksi berhasil dipulihkan'
    ]);
  }

  /**
  * Menghapus transaksi secara permanen.
  */
  public function forceDelete($id): JsonResponse
  {
    $transaction = Transaction::withTrashed()->findOrFail($id);

    if ($transaction->wallet->user_id !== request()->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $transaction->forceDelete();

    return response()->json([
      'success' => true,
      'message' => 'Transaksi dihapus permanen'
    ]);
  }

  public function transfer(TransferRequest $request): JsonResponse
  {
    $fromWallet = Wallet::findOrFail($request->from_wallet_id);
    $toWallet = Wallet::findOrFail($request->to_wallet_id);
    $amount = Money::of($request->amount, $fromWallet->currency);

    // Periksa saldo cukup
    if ($fromWallet->balance->isLessThan($amount)) {
      return response()->json([
        'success' => false,
        'message' => 'Saldo dompet asal tidak mencukupi.'
      ], 400);
    }

    DB::transaction(function () use ($fromWallet, $toWallet, $request, $amount) {
      // Buat transaksi transfer di dompet asal (expense)
      $fromTransaction = new Transaction([
        'wallet_id' => $fromWallet->id,
        'category_id' => $this->getTransferOutCategoryId(),
        'type' => TransactionType::TRANSFER,
        'amount' => $amount,
        'transaction_date' => $request->transaction_date,
        'description' => $request->description ?: 'Transfer ke ' . $toWallet->name,
        'metadata' => [
          'transfer_to_wallet_id' => $toWallet->id,
          'transfer_to_wallet_name' => $toWallet->name
        ]
      ]);
      $fromTransaction->save();

      // Buat transaksi transfer di dompet tujuan (income)
      $toTransaction = new Transaction([
        'wallet_id' => $toWallet->id,
        'category_id' => $this->getTransferInCategoryId(),
        'type' => TransactionType::TRANSFER,
        'amount' => $amount,
        'transaction_date' => $request->transaction_date,
        'description' => $request->description ?: 'Transfer dari ' . $fromWallet->name,
        'metadata' => [
          'transfer_from_wallet_id' => $fromWallet->id,
          'transfer_from_wallet_name' => $fromWallet->name
        ]
      ]);
      $toTransaction->save();

      // Update saldo
      $fromWallet->withdraw($amount);
      $toWallet->deposit($amount);
    });

    return response()->json([
      'success' => true,
      'message' => 'Transfer berhasil dilakukan.'
    ]);
  }

  private function getTransferOutCategoryId(): int
  {
    return Category::firstOrCreate(
      ['name' => 'Transfer Keluar'],
      ['type' => CategoryType::EXPENSE, 'is_system' => true, 'icon' => 'bi-arrow-right', 'color' => '#6c757d']
    )->id;
  }

  private function getTransferInCategoryId(): int
  {
    return Category::firstOrCreate(
      ['name' => 'Transfer Masuk'],
      ['type' => CategoryType::INCOME, 'is_system' => true, 'icon' => 'bi-arrow-left', 'color' => '#6c757d']
    )->id;
  }
}