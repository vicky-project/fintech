<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Http\Requests\TransactionRequest;
use Modules\FinTech\Http\Requests\TransferRequest;
use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Services\TransactionService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TransactionController extends Controller
{
  protected TransactionService $transactionService;

  public function __construct(TransactionService $transactionService) {
    $this->transactionService = $transactionService;
  }

  public function index(): JsonResponse
  {
    $filters = request()->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'type' => 'nullable|in:income,expense,transfer',
      'category_id' => 'nullable|exists:fintech_categories,id',
      'month' => 'nullable|date_format:Y-m',
      'per_page' => 'integer|min:1|max:100'
    ]);

    $perPage = request('per_page', 20);
    $result = $this->transactionService->getTransactions(request()->user(), $filters, $perPage);

    return response()->json([
      'success' => true,
      'data' => $result['data'],
      'summary' => $result['summary'],
      'pagination' => $result['pagination'],
    ]);
  }

  public function store(TransactionRequest $request): JsonResponse
  {
    try {
      $transaction = $this->transactionService->createTransaction($request->user(), $request->validated());
      return response()->json(['success' => true, 'message' => 'Transaksi berhasil disimpan'], 201);
    } catch (\Exception $e) {
      return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
    }
  }

  public function show(Transaction $transaction): JsonResponse
  {
    try {
      $data = $this->transactionService->getTransactionDetail(request()->user(), $transaction);
      return response()->json(['success' => true, 'data' => $data]);
    } catch (HttpException $e) {
      return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
    }
  }

  public function update(TransactionRequest $request, Transaction $transaction): JsonResponse
  {
    try {
      $this->transactionService->updateTransaction($request->user(), $transaction, $request->validated());
      return response()->json(['success' => true, 'message' => 'Transaksi berhasil diperbarui']);
    } catch (\Exception $e) {
      return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
    }
  }

  public function destroy(Transaction $transaction): JsonResponse
  {
    try {
      $this->transactionService->deleteTransaction(request()->user(), $transaction);
      return response()->json(['success' => true, 'message' => 'Transaksi dipindahkan ke tempat sampah']);
    } catch (HttpException $e) {
      return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
    }
  }

  /**
  * Bulk soft delete transactions for a specific month and wallet.
  */
  public function bulkDestroy(Request $request): JsonResponse
  {
    $request->validate([
      'wallet_id' => 'required|exists:fintech_wallets,id',
      'month' => 'required|date_format:Y-m',
    ]);

    $user = $request->user();
    $walletId = $request->wallet_id;
    $month = $request->month;

    // Pastikan wallet milik user
    $wallet = Wallet::where('user_id', $user->id)->find($walletId);
    if (!$wallet) {
      return response()->json([
        'success' => false,
        'message' => 'Dompet tidak ditemukan atau bukan milik Anda.'
      ], 403);
    }

    try {
      $count = $this->transactionService->bulkDeleteTransactions($user, $walletId, $month);

      if ($count === 0) {
        return response()->json([
          'success' => false,
          'message' => 'Tidak ada transaksi pada periode tersebut.'
        ], 404);
      }

      return response()->json([
        'success' => true,
        'message' => "{$count} transaksi berhasil dipindahkan ke tempat sampah."
      ]);
    } catch(\Exception $e) {
      return response()->json(["message" => $e->getMessage()], $e->getStatusCode());
    }
  }

  public function trashed(): JsonResponse
  {
    $perPage = request('per_page',
      20);
    $result = $this->transactionService->getTrashedTransactions(request()->user(),
      $perPage);

    return response()->json([
      'success' => true,
      'data' => $result['data'],
      'pagination' => $result['pagination'],
    ]);
  }

  public function restore($id): JsonResponse
  {
    try {
      $this->transactionService->restoreTransaction(request()->user(),
        $id);
      return response()->json(['success' => true,
        'message' => 'Transaksi berhasil dipulihkan']);
    } catch (HttpException $e) {
      return response()->json(['message' => $e->getMessage()],
        $e->getStatusCode());
    } catch (\Exception $e) {
      return response()->json(['success' => false,
        'message' => $e->getMessage()],
        400);
    }
  }

  public function forceDelete($id): JsonResponse
  {
    try {
      $this->transactionService->forceDeleteTransaction(request()->user(),
        $id);
      return response()->json(['success' => true,
        'message' => 'Transaksi dihapus permanen']);
    } catch (HttpException $e) {
      return response()->json(['message' => $e->getMessage()],
        $e->getStatusCode());
    }
  }
}