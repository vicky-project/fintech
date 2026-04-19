<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Http\Requests\WalletRequest;
use Modules\FinTech\Models\Wallet;
use Brick\Money\Money;
use Brick\Money\Exception\MoneyMismatchException;

class WalletController extends Controller
{
  /**
  * Display a listing of the user's wallets.
  */
  public function index(): JsonResponse
  {
    $wallets = request()->user()
    ->wallets()
    ->where('is_active', true)
    ->with('currencyDetails')
    ->orderBy('name')
    ->get()
    ->map(function ($wallet) {
      return [
        'id' => $wallet->id,
        'name' => $wallet->name,
        'balance' => $wallet->getBalanceFloat(),
        'formatted_balance' => $wallet->getFormattedBalance(),
        'currency' => [
          'code' => $wallet->currency,
          'name' => $wallet->currencyDetails->name ?? $wallet->currency,
          'symbol' => $wallet->currencyDetails->symbol ?? $wallet->currency,
          'precision' => $wallet->currencyDetails->precision ?? 2,
        ],
        'description' => $wallet->description,
        'is_active' => $wallet->is_active,
      ];
    });

    return response()->json([
      'success' => true,
      'data' => $wallets
    ]);
  }

  /**
  * Store a newly created wallet.
  */
  public function store(WalletRequest $request): JsonResponse
  {
    try {
      $initialBalance = Money::of(
        $request->input('initial_balance', 0),
        $request->input('currency', 'IDR')
      );

      $wallet = new Wallet();
      $wallet->user_id = $request->user()->id;
      $wallet->name = $request->name;
      $wallet->currency = $request->input('currency', 'IDR');
      $wallet->description = $request->description;
      $wallet->balance = $initialBalance;
      $wallet->save();

      return response()->json([
        'success' => true,
        'message' => 'Dompet berhasil dibuat',
        'data' => [
          'id' => $wallet->id,
          'name' => $wallet->name,
          'balance' => $wallet->getBalanceFloat(),
          'formatted_balance' => $wallet->getFormattedBalance(),
          'currency' => $wallet->currency,
        ]
      ], 201);
    } catch (MoneyMismatchException $e) {
      return response()->json([
        'success' => false,
        'message' => 'Mata uang tidak sesuai dengan dompet yang ada.'
      ], 422);
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => 'Gagal membuat dompet: ' . $e->getMessage()
      ], 500);
    }
  }

  /**
  * Display the specified wallet.
  */
  public function show(Wallet $wallet): JsonResponse
  {
    if ($wallet->user_id !== request()->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $wallet->load('currencyDetails');

    return response()->json([
      'success' => true,
      'data' => [
        'id' => $wallet->id,
        'name' => $wallet->name,
        'balance' => $wallet->getBalanceFloat(),
        'formatted_balance' => $wallet->getFormattedBalance(),
        'currency' => [
          'code' => $wallet->currency,
          'name' => $wallet->currencyDetails->name ?? $wallet->currency,
          'symbol' => $wallet->currencyDetails->symbol ?? $wallet->currency,
          'precision' => $wallet->currencyDetails->precision ?? 2,
        ],
        'description' => $wallet->description,
        'transaction_count' => $wallet->transactions()->count(),
      ]
    ]);
  }

  /**
  * Update the specified wallet.
  */
  public function update(WalletRequest $request, Wallet $wallet): JsonResponse
  {
    if ($wallet->user_id !== $request->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $validated = $request->validated();
    // Hapus field yang tidak boleh diupdate
    unset($validated['initial_balance'], $validated['currency']);

    $wallet->update($validated);

    return response()->json([
      'success' => true,
      'message' => 'Dompet berhasil diperbarui',
      'data' => [
        'id' => $wallet->id,
        'name' => $wallet->name,
        'balance' => $wallet->getBalanceFloat(),
        'formatted_balance' => $wallet->getFormattedBalance(),
        'is_active' => $wallet->is_active,
      ]
    ]);
  }
}