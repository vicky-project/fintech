<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Category;
use Modules\FinTech\Enums\TransactionType;
use Brick\Money\Money;

class TransactionController extends Controller
{
  /**
  * Display a listing of transactions.
  */
  public function index(Request $request): JsonResponse
  {
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'type' => 'nullable|in:' . implode(',', TransactionType::values()),
      'category_id' => 'nullable|exists:fintech_categories,id',
      'month' => 'nullable|date_format:Y-m',
      'per_page' => 'integer|min:1|max:100'
    ]);

    $query = Transaction::with(['wallet', 'category'])
    ->whereHas('wallet', function ($q) use ($request) {
      $q->where('user_id', $request->user()->id);
    });

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

    $transformedData = $transactions->through(function ($transaction) {
      return [
        'id' => $transaction->id,
        'type' => $transaction->type->value,
        'type_label' => $transaction->type->label(),
        'type_icon' => $transaction->type->icon(),
        'type_sign' => $transaction->type->sign(),
        'category' => [
          'id' => $transaction->category->id,
          'name' => $transaction->category->name,
          'icon' => $transaction->category->icon,
          'color' => $transaction->category->color,
        ],
        'description' => $transaction->description,
        'amount' => $transaction->getAmountFloat(),
        'formatted_amount' => $transaction->getFormattedAmount(),
        'transaction_date' => $transaction->transaction_date->toDateString(),
        'wallet' => [
          'id' => $transaction->wallet->id,
          'name' => $transaction->wallet->name,
        ],
        'metadata' => $transaction->metadata,
      ];
    });

    return response()->json([
      'success' => true,
      'data' => $transformedData
    ]);
  }

  /**
  * Store a newly created transaction.
  */
  public function store(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'wallet_id' => 'required|exists:fintech_wallets,id',
      'category_id' => 'required|exists:fintech_categories,id',
      'type' => 'required|in:' . implode(',', TransactionType::values()),
      'amount' => 'required|numeric|min:0.01',
      'description' => 'nullable|string',
      'transaction_date' => 'required|date|before_or_equal:today'
    ]);

    $wallet = Wallet::findOrFail($validated['wallet_id']);
    if ($wallet->user_id !== $request->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $category = Category::findOrFail($validated['category_id']);

    try {
      $amount = Money::of($validated['amount'], $wallet->currency);

      DB::transaction(function () use ($wallet, $validated, $amount) {
        $transaction = new Transaction($validated);
        $transaction->amount = $amount;
        $transaction->save();

        if ($validated['type'] === TransactionType::INCOME->value) {
          $wallet->deposit($amount);
        } elseif ($validated['type'] === TransactionType::EXPENSE->value) {
          $wallet->withdraw($amount);
        }
        // Note: TRANSFER type will be handled separately
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
        'type_icon' => $transaction->type->icon(),
        'category' => [
          'id' => $transaction->category->id,
          'name' => $transaction->category->name,
          'icon' => $transaction->category->icon,
          'color' => $transaction->category->color,
        ],
        'description' => $transaction->description,
        'amount' => $transaction->getAmountFloat(),
        'formatted_amount' => $transaction->getFormattedAmount(),
        'transaction_date' => $transaction->transaction_date->toDateString(),
        'wallet' => [
          'id' => $transaction->wallet->id,
          'name' => $transaction->wallet->name,
        ],
        'metadata' => $transaction->metadata,
      ]
    ]);
  }
}