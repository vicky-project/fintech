<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Models\BankStatement;
use Modules\FinTech\Models\StatementTransaction;
use Modules\FinTech\Services\PdfDecryptor;
use Modules\FinTech\Services\BankParserManager;
use Modules\FinTech\Services\CategorizationService;
use Modules\FinTech\Enums\StatementStatus;
use Modules\FinTech\Enums\StatementType;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class StatementController extends Controller
{
  protected PdfDecryptor $decryptor;
  protected BankParserManager $parserManager;
  protected CategorizationService $categorizationService;

  public function __construct(
    PdfDecryptor $decryptor,
    BankParserManager $parserManager,
    CategorizationService $categorizationService
  ) {
    $this->decryptor = $decryptor;
    $this->parserManager = $parserManager;
    $this->categorizationService = $categorizationService;
  }

  /**
  * Upload dan proses file statement bank.
  */
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

    $user = $request->user();
    $file = $request->file('file');
    $password = $request->input('password');
    $walletId = $request->input('wallet_id');

    $wallet = \Modules\FinTech\Models\Wallet::where('user_id', $user->id)
    ->where('id', $walletId)
    ->first();

    if (!$wallet) {
      return response()->json([
        'success' => false,
        'message' => 'Dompet tidak valid.'
      ], 422);
    }

    $tempPath = $file->storeAs(
      'temp/statements/' . $user->id,
      uniqid() . '_' . $file->getClientOriginalName()
    );

    $fullPath = Storage::path($tempPath);
    $processedPath = $fullPath;

    $statement = BankStatement::create([
      'user_id' => $user->id,
      'wallet_id' => $walletId,
      'original_filename' => $file->getClientOriginalName(),
      'file_path' => $tempPath,
      'status' => StatementStatus::UPLOADED,
    ]);

    try {
      if ($file->getClientOriginalExtension() === 'pdf' && $this->decryptor->isEncrypted($fullPath)) {
        if (!$password) {
          throw new \Exception("File PDF ini diproteksi password. Silakan masukkan password.");
        }

        $processedPath = $this->decryptor->decrypt($fullPath, $password);
        $statement->updateStatus(StatementStatus::DECRYPTED);
      }

      $result = $this->parserManager->parse($processedPath);

      $statement->update([
        'bank_code' => $result['bank_code'],
        'meta_data' => ['transaction_count' => count($result['transactions'])],
      ]);
      $statement->updateStatus(StatementStatus::PARSED);

      foreach ($result['transactions'] as $trx) {
        $type = $trx['type'] ?? StatementType::fromDescription($trx['description'], $trx['amount']);
        $category = $this->categorizationService->categorize($trx['description'], $type);

        StatementTransaction::create([
          'statement_id' => $statement->id,
          'transaction_date' => $trx['date'],
          'description' => $trx['description'],
          'amount' => $trx['amount'],
          'type' => $type,
          'category_id' => $category?->id,
          'raw_data' => $trx,
        ]);
      }

      return response()->json([
        'success' => true,
        'message' => 'Statement berhasil diproses.',
        'data' => [
          'statement_id' => $statement->id,
          'bank_code' => $result['bank_code'],
          'transaction_count' => count($result['transactions']),
        ]
      ]);

    } catch (\Exception $e) {
      $statement->updateStatus(StatementStatus::FAILED, ['error' => $e->getMessage()]);

      return response()->json([
        'success' => false,
        'message' => $e->getMessage()
      ], 422);
    } finally {
      if (isset($processedPath) && $processedPath !== $fullPath && file_exists($processedPath)) {
        @unlink($processedPath);
      }
    }
  }

  /**
  * Preview transaksi hasil parsing.
  */
  public function preview(BankStatement $statement): JsonResponse
  {
    if ($statement->user_id !== request()->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $transactions = $statement->transactions()
    ->with('category')
    ->notImported()
    ->get()
    ->map(fn($trx) => [
      'id' => $trx->id,
      'date' => $trx->transaction_date->toDateString(),
      'description' => $trx->description,
      'amount' => $trx->getAmountFloat(),
      'formatted_amount' => $trx->getFormattedAmount(),
      'type' => $trx->type?->value,
      'type_label' => $trx->type?->label(),
      'category' => $trx->category ? [
        'id' => $trx->category->id,
        'name' => $trx->category->name,
        'icon' => $trx->category->icon,
        'color' => $trx->category->color,
      ] : null,
    ]);

    // Ambil semua kategori untuk dropdown edit
    $categories = \Modules\FinTech\Models\Category::active()
    ->orderBy('name')
    ->get(['id', 'name', 'type', 'icon', 'color']);

    return response()->json([
      'success' => true,
      'data' => [
        'statement_id' => $statement->id,
        'wallet' => [
          'id' => $statement->wallet->id,
          'name' => $statement->wallet->name,
        ],
        'transactions' => $transactions,
        'categories' => $categories,
      ]
    ]);
  }

  /**
  * Import transaksi terpilih.
  */
  public function import(Request $request, BankStatement $statement): JsonResponse
  {
    if ($statement->user_id !== $request->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $request->validate([
      'transaction_ids' => 'required|array|min:1',
      'transaction_ids.*' => 'required|exists:fintech_statement_transactions,id',
    ]);

    $wallet = $statement->wallet;
    $transactions = $statement->transactions()
    ->notImported()
    ->whereIn('id', $request->transaction_ids)
    ->get();

    if ($transactions->isEmpty()) {
      return response()->json([
        'success' => false,
        'message' => 'Tidak ada transaksi yang valid untuk diimpor.'
      ], 422);
    }

    DB::beginTransaction();
    try {
      $imported = 0;
      foreach ($transactions as $trx) {
        $transactionType = $trx->type?->toTransactionType();
        if (!$transactionType) {
          continue;
        }

        // Buat transaksi utama
        \Modules\FinTech\Models\Transaction::create([
          'wallet_id' => $wallet->id,
          'category_id' => $trx->category_id,
          'type' => $transactionType,
          'amount' => $trx->amount,
          'transaction_date' => $trx->transaction_date,
          'description' => $trx->description,
          'metadata' => ['imported_from_statement_id' => $statement->id],
        ]);

        // Update saldo wallet
        if ($transactionType === \Modules\FinTech\Enums\TransactionType::INCOME) {
          $wallet->deposit($trx->amount);
        } else {
          $wallet->withdraw($trx->amount);
        }

        $trx->markAsImported();
        $imported++;
      }

      // Update status statement
      $remaining = $statement->transactions()->notImported()->count();
      if ($remaining === 0) {
        $statement->updateStatus(StatementStatus::IMPORTED);
      }

      DB::commit();

      return response()->json([
        'success' => true,
        'message' => "{$imported} transaksi berhasil diimpor.",
        'data' => ['imported' => $imported]
      ]);
    } catch (\Exception $e) {
      DB::rollBack();
      return response()->json([
        'success' => false,
        'message' => 'Gagal mengimpor: ' . $e->getMessage()
      ], 500);
    }
  }

  /**
  * Update kategori transaksi statement.
  */
  public function updateCategory(Request $request, StatementTransaction $transaction): JsonResponse
  {
    if ($transaction->statement->user_id !== $request->user()->id) {
      return response()->json(['message' => 'Unauthorized'], 403);
    }

    $request->validate([
      'category_id' => 'required|exists:fintech_categories,id',
    ]);

    $transaction->category_id = $request->category_id;
    $transaction->save();

    return response()->json([
      'success' => true,
      'message' => 'Kategori diperbarui',
      'data' => [
        'id' => $transaction->id,
        'category' => [
          'id' => $transaction->category->id,
          'name' => $transaction->category->name,
          'icon' => $transaction->category->icon,
          'color' => $transaction->category->color,
        ]
      ]
    ]);
  }
}