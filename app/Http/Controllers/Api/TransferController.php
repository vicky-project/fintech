<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Models\Transfer;
use Modules\FinTech\Http\Requests\TransferRequest;
use Brick\Money\Money;

class TransferController extends Controller
{
  public function index(): JsonResponse
  {
    $request = request();
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'per_page' => 'integer|min:1|max:100'
    ]);

    $query = Transfer::with(['fromWallet', 'toWallet'])
    ->where(function ($q) use ($request) {
      $q->whereHas('fromWallet', fn($q) => $q->where('user_id', $request->user()->id))
      ->orWhereHas('toWallet', fn($q) => $q->where('user_id', $request->user()->id));
    })
    ->orderBy('transfer_date', 'desc')
    ->orderBy('id', 'desc');

    if ($walletId = $request->input('wallet_id')) {
      $query->where(function ($q) use ($walletId) {
        $q->where('from_wallet_id', $walletId)
        ->orWhere('to_wallet_id', $walletId);
      });
    }

    $transfers = $query->paginate($request->input('per_page', 20));

    $transformed = $transfers->through(fn($t) => [
      'id' => $t->id,
      'from_wallet' => [
        'id' => $t->fromWallet->id,
        'name' => $t->fromWallet->name,
        'currency' => $t->fromWallet->currency
      ],
      'to_wallet' => [
        'id' => $t->toWallet->id,
        'name' => $t->toWallet->name,
        'currency' => $t->toWallet->currency
      ],
      'amount' => $t->getAmountFloat(),
      'formatted_amount' => $t->getFormattedAmount(),
      'transfer_date' => $t->transfer_date->toDateString(),
      'description' => $t->description,
    ]);

    return response()->json(['success' => true, 'data' => $transformed]);
  }

  public function store(TransferRequest $request): JsonResponse
  {
    $fromWallet = Wallet::findOrFail($request->from_wallet_id);
    $toWallet = Wallet::findOrFail($request->to_wallet_id);
    $amount = Money::of($request->amount, $fromWallet->currency);

    if ($fromWallet->balance->isLessThan($amount)) {
      return response()->json([
        'success' => false,
        'message' => 'Saldo dompet asal tidak mencukupi.'
      ], 400);
    }

    DB::transaction(function () use ($fromWallet, $toWallet, $request, $amount) {
      $transfer = new Transfer($request->validated());
      $transfer->amount = $amount;
      $transfer->save();

      $fromWallet->withdraw($amount);
      $toWallet->deposit($amount);
    });

    return response()->json([
      'success' => true,
      'message' => 'Transfer berhasil dilakukan.'
    ], 201);
  }

  public function update(TransferRequest $request, Transfer $transfer): JsonResponse
  {
    if ($transfer->fromWallet->user_id !== $request->user()->id &&
      $transfer->toWallet->user_id !== $request->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $validated = $request->validated();
    unset($validated['from_wallet_id'], $validated['to_wallet_id']);

    DB::transaction(function () use ($transfer, $validated, $request) {
      $oldAmount = $transfer->amount;
      $transfer->fromWallet->deposit($oldAmount);
      $transfer->toWallet->withdraw($oldAmount);

      $transfer->fill($validated);
      if ($request->has('amount')) {
        $transfer->amount = Money::of($request->amount, $transfer->fromWallet->currency);
      }
      $transfer->save();

      $newAmount = $transfer->amount;
      $transfer->fromWallet->withdraw($newAmount);
      $transfer->toWallet->deposit($newAmount);
    });

    return response()->json([
      'success' => true,
      'message' => 'Transfer berhasil diperbarui.'
    ]);
  }

  public function destroy(Transfer $transfer): JsonResponse
  {
    if ($transfer->fromWallet->user_id !== request()->user()->id &&
      $transfer->toWallet->user_id !== request()->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    DB::transaction(function () use ($transfer) {
      $transfer->fromWallet->deposit($transfer->amount);
      $transfer->toWallet->withdraw($transfer->amount);
      $transfer->delete();
    });

    return response()->json([
      'success' => true,
      'message' => 'Transfer dipindahkan ke tempat sampah.'
    ]);
  }

  public function trashed(): JsonResponse
  {
    $request = request();
    $query = Transfer::onlyTrashed()
    ->with(['fromWallet', 'toWallet'])
    ->where(function ($q) use ($request) {
      $q->whereHas('fromWallet', fn($q) => $q->where('user_id', $request->user()->id))
      ->orWhereHas('toWallet', fn($q) => $q->where('user_id', $request->user()->id));
    })
    ->orderBy('deleted_at', 'desc');

    $transfers = $query->paginate($request->input('per_page', 20));

    $transformed = $transfers->through(fn($t) => [
      'id' => $t->id,
      'from_wallet' => ['id' => $t->fromWallet->id, 'name' => $t->fromWallet->name],
      'to_wallet' => ['id' => $t->toWallet->id, 'name' => $t->toWallet->name],
      'amount' => $t->getAmountFloat(),
      'formatted_amount' => $t->getFormattedAmount(),
      'transfer_date' => $t->transfer_date->toDateString(),
      'description' => $t->description,
      'deleted_at' => $t->deleted_at->toDateTimeString(),
    ]);

    return response()->json(['success' => true, 'data' => $transformed]);
  }

  public function restore($id): JsonResponse
  {
    $transfer = Transfer::onlyTrashed()->findOrFail($id);

    if ($transfer->fromWallet->user_id !== request()->user()->id &&
      $transfer->toWallet->user_id !== request()->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    DB::transaction(function () use ($transfer) {
      $transfer->fromWallet->withdraw($transfer->amount);
      $transfer->toWallet->deposit($transfer->amount);
      $transfer->restore();
    });

    return response()->json([
      'success' => true,
      'message' => 'Transfer berhasil dipulihkan.'
    ]);
  }

  public function forceDelete($id): JsonResponse
  {
    $transfer = Transfer::withTrashed()->findOrFail($id);

    if ($transfer->fromWallet->user_id !== request()->user()->id &&
      $transfer->toWallet->user_id !== request()->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $transfer->forceDelete();

    return response()->json([
      'success' => true,
      'message' => 'Transfer dihapus permanen.'
    ]);
  }
}