<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Models\Wallet;
use Brick\Money\Money;
use Brick\Money\Exception\MoneyMismatchException;

class WalletController extends Controller
{
  /**
  * Display a listing of the user's wallets.
  */
  public function index(Request $request): JsonResponse
  {
    $wallets = $request->user()
    ->wallets()
    ->where('is_active', true)
    ->orderBy('name')
    ->get()
    ->map(function ($wallet) {
      return [
        'id' => $wallet->id,
        'name' => $wallet->name,
        'balance' => $wallet->getBalanceFloat(),
        'formatted_balance' => $wallet->getFormattedBalance(),
        'currency' => $wallet->currency,
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
  public function store(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'name' => 'required|string|max:100',
      'currency' => 'string|size:3',
      'description' => 'nullable|string',
      'initial_balance' => 'numeric|min:0'
    ]);

    try {
      $initialBalance = Money::of(
        $validated['initial_balance'] ?? 0,
        $validated['currency'] ?? 'IDR'
      );

      $wallet = new Wallet();
      $wallet->user_id = $request->user()->id;
      $wallet->name = $validated['name'];
      $wallet->currency = $validated['currency'] ?? 'IDR';
      $wallet->description = $validated['description'] ?? null;
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

    return response()->json([
      'success' => true,
      'data' => [
        'id' => $wallet->id,
        'name' => $wallet->name,
        'balance' => $wallet->getBalanceFloat(),
        'formatted_balance' => $wallet->getFormattedBalance(),
        'currency' => $wallet->currency,
        'description' => $wallet->description,
        'transaction_count' => $wallet->transactions()->count(),
      ]
    ]);
  }

  /**
  * Update the specified wallet.
  */
  public function update(Request $request, Wallet $wallet): JsonResponse
  {
    if ($wallet->user_id !== $request->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $validated = $request->validate([
      'name' => 'string|max:100',
      'description' => 'nullable|string',
      'is_active' => 'boolean'
    ]);

    $wallet->update($validated);

    return response()->json([
      'success' => true,
      'message' => 'Dompet berhasil diperbarui',
      'data' => [
        'id' => $wallet->id,
        'name' => $wallet->name,
        'balance' => $wallet->getBalanceFloat(),
        'formatted_balance' => $wallet->getFormattedBalance(),
      ]
    ]);
  }
}