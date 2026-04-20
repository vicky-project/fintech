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
    $query = Transfer::with(['fromWallet', 'toWallet'])
    ->where(function ($q) use ($request) {
      $q->whereHas('fromWallet', fn($q) => $q->where('user_id', $request->user()->id))
      ->orWhereHas('toWallet', fn($q) => $q->where('user_id', $request->user()->id));
    })
    ->orderBy('transfer_date', 'desc');

    if ($walletId = $request->input('wallet_id')) {
      $query->where(function ($q) use ($walletId) {
        $q->where('from_wallet_id', $walletId)
        ->orWhere('to_wallet_id', $walletId);
      });
    }

    $transfers = $query->paginate(20);

    $transformed = $transfers->through(fn($t) => [
      'id' => $t->id,
      'from_wallet' => ['id' => $t->fromWallet->id, 'name' => $t->fromWallet->name],
      'to_wallet' => ['id' => $t->toWallet->id, 'name' => $t->toWallet->name],
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
      return response()->json(['success' => false, 'message' => 'Saldo tidak mencukupi.'], 400);
    }

    DB::transaction(function () use ($fromWallet, $toWallet, $request, $amount) {
      $transfer = Transfer::create($request->validated() + ['amount' => $amount]);
      $fromWallet->withdraw($amount);
      $toWallet->deposit($amount);
    });

    return response()->json(['success' => true, 'message' => 'Transfer berhasil.']);
  }

  public function update(TransferRequest $request, Transfer $transfer): JsonResponse
  {
    // Otorisasi...
    DB::transaction(function () use ($request, $transfer) {
      $oldAmount = $transfer->amount;
      // Kembalikan saldo
      $transfer->fromWallet->deposit($oldAmount);
      $transfer->toWallet->withdraw($oldAmount);

      $transfer->update($request->validated());

      $newAmount = $transfer->amount;
      $transfer->fromWallet->withdraw($newAmount);
      $transfer->toWallet->deposit($newAmount);
    });

    return response()->json(['success' => true, 'message' => 'Transfer diperbarui.']);
  }

  public function destroy(Transfer $transfer): JsonResponse
  {
    DB::transaction(function () use ($transfer) {
      $transfer->fromWallet->deposit($transfer->amount);
      $transfer->toWallet->withdraw($transfer->amount);
      $transfer->delete();
    });
    return response()->json(['success' => true, 'message' => 'Transfer dihapus.']);
  }

  // trashed, restore, forceDelete...
}