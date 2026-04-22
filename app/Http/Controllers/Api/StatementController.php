<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\StatementService;
use Modules\FinTech\Models\BankStatement;
use Modules\FinTech\Models\StatementTransaction;
use Illuminate\Support\Facades\Validator;

class StatementController extends Controller
{
  protected StatementService $statementService;

  public function __construct(StatementService $statementService) {
    $this->statementService = $statementService;
  }

  public function index(Request $request): JsonResponse
  {
    $statements = $this->statementService->getPaginatedStatements(
      $request->user()->id,
      $request->input('per_page', 20)
    );

    return response()->json([
      'success' => true,
      'data' => $statements
    ]);
  }

  public function destroy(BankStatement $statement): JsonResponse
  {
    if ($statement->user_id !== request()->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $this->statementService->deleteStatement($statement->user_id, $statement->id);

    return response()->json([
      'success' => true,
      'message' => 'Statement dihapus.'
    ]);
  }

  public function upload(Request $request): JsonResponse
  {
    $validator = Validator::make($request->all(), [
      'file' => 'required|file|mimes:pdf,xls,xlsx,csv|max:10240',
      'password' => 'nullable|string|max:50',
      'wallet_id' => 'required|exists:fintech_wallets,id',
    ]);

    if ($validator->fails()) {
      return response()->json([
        'success' => false,
        'message' => $validator->errors()->first()
      ], 422);
    }

    try {
      $result = $this->statementService->uploadStatement(
        $request->user()->id,
        $request->file('file'),
        $request->input('password'),
        $request->input('wallet_id')
      );

      return response()->json([
        'success' => true,
        'message' => 'Statement berhasil diproses.',
        'data' => $result
      ]);
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => $e->getMessage()
      ], 422);
    }
  }

  public function preview(BankStatement $statement): JsonResponse
  {
    if ($statement->user_id !== request()->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $data = $this->statementService->previewStatement($statement->user_id, $statement->id);

    return response()->json([
      'success' => true,
      'data' => $data
    ]);
  }

  public function import(Request $request, BankStatement $statement): JsonResponse
  {
    if ($statement->user_id !== $request->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $request->validate([
      'transaction_ids' => 'required|array|min:1',
      'transaction_ids.*' => 'required|exists:fintech_statement_transactions,id',
    ]);

    try {
      $result = $this->statementService->importStatement(
        $statement->user_id,
        $statement->id,
        $request->transaction_ids
      );

      $message = "{$result['imported']} transaksi berhasil diimpor.";
      if ($result['skipped'] > 0) {
        $message .= " {$result['skipped']} transaksi dilewati.";
      }

      return response()->json([
        'success' => true,
        'message' => $message,
        'data' => $result
      ]);
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => 'Gagal mengimpor: ' . $e->getMessage()
      ], 500);
    }
  }

  public function updateCategory(Request $request, StatementTransaction $transaction): JsonResponse
  {
    if ($transaction->statement->user_id !== $request->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $request->validate([
      'category_id' => 'required|exists:fintech_categories,id',
    ]);

    $data = $this->statementService->updateTransactionCategory(
      $transaction->statement->user_id,
      $transaction->id,
      $request->category_id
    );

    return response()->json([
      'success' => true,
      'message' => 'Kategori diperbarui',
      'data' => $data
    ]);
  }
}