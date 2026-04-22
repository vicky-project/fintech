<?php

namespace Modules\FinTech\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\FinTech\Models\BankStatement;
use Modules\FinTech\Models\StatementTransaction;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Models\Category;
use Modules\FinTech\Enums\StatementStatus;
use Modules\FinTech\Enums\StatementType;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Modules\FinTech\Enums\TransactionType;

class StatementService
{
  protected PdfDecryptor $decryptor;
  protected BankParserManager $parserManager;
  protected CategorizationService $categorizationService;
  protected TransactionService $transactionService;

  public function __construct(
    PdfDecryptor $decryptor,
    BankParserManager $parserManager,
    CategorizationService $categorizationService,
    TransactionService $transactionService
  ) {
    $this->decryptor = $decryptor;
    $this->parserManager = $parserManager;
    $this->categorizationService = $categorizationService;
    $this->transactionService = $transactionService;
  }

  /**
  * Mendapatkan list statement untuk user.
  */
  public function getPaginatedStatements(int $userId, int $perPage = 20): LengthAwarePaginator
  {
    $statements = BankStatement::where('user_id', $userId)
    ->with(['wallet'])
    ->orderBy('created_at', 'desc')
    ->paginate($perPage);

    $statements->through(fn($s) => [
      'id' => $s->id,
      'original_filename' => $s->original_filename,
      'bank_code' => $s->bank_code,
      'status' => $s->status->value,
      'status_label' => $s->status->label(),
      'wallet' => $s->wallet ? [
        'id' => $s->wallet->id,
        'name' => $s->wallet->name,
      ] : null,
      'meta_data' => $s->meta_data,
      'processed_at' => $s->processed_at?->toDateTimeString(),
      'created_at' => $s->created_at->toDateTimeString(),
      'remaining_count' => $s->transactions()->notImported()->count(),
    ]);

    return $statements;
  }

  /**
  * Menghapus statement dan file terkait.
  */
  public function deleteStatement(int $userId, int $statementId): void
  {
    $statement = BankStatement::where('user_id', $userId)->findOrFail($statementId);

    if ($statement->file_path) {
      Storage::delete($statement->file_path);
    }

    $statement->delete();
  }

  /**
  * Upload dan proses file statement.
  */
  public function uploadStatement(int $userId, UploadedFile $file, ?string $password, int $walletId): array
  {
    // Verifikasi wallet
    $wallet = Wallet::where('user_id', $userId)->find($walletId);
    if (!$wallet) {
      throw new \Exception('Dompet tidak valid.');
    }

    $tempPath = $file->storeAs(
      'temp/statements/' . $userId,
      uniqid() . '_' . $file->getClientOriginalName()
    );

    $fullPath = Storage::path($tempPath);
    $processedPath = $fullPath;

    $statement = BankStatement::create([
      'user_id' => $userId,
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
      \Log::debug("Parser result", $result);

      $statement->update([
        'bank_code' => $result['bank_code'],
        'meta_data' => ['transaction_count' => count($result['transactions'])],
      ]);
      $statement->updateStatus(StatementStatus::PARSED);

      $insertData = [];
      $now = now();
      foreach ($result['transactions'] as $trx) {
        $type = $trx['type'] ?? StatementType::fromDescription($trx['description'], $trx['amount']);
        $category = $this->categorizationService->categorize($trx['description'], $type);

        $insertData[] = [
          'statement_id' => $statement->id,
          'transaction_date' => $trx['date'],
          'description' => $trx['description'],
          'amount' => $trx['amount'],
          'type' => $type,
          'category_id' => $category?->id,
          'raw_data' => $trx,
          'created_at' => $now,
          'updated_at' => $now
        ];
      }

      foreach (array_chunk($insertData, 500) as $chunk) {
        StatementTransaction::insert($chunk);
      }

      return [
        'statement_id' => $statement->id,
        'bank_code' => $result['bank_code'],
        'transaction_count' => count($result['transactions']),
      ];
    } catch (\Exception $e) {
      \Log::error("Failed to process upload statement.", [
        "message" => $e->getMessage(),
        "filepath" => $fullPath,
        "user_id" => $userId,
        "trace" => $e->getTraceAsString()
      ]);

      $statement->updateStatus(StatementStatus::FAILED, ['error' => $e->getMessage()]);
      throw $e;
    } finally {
      if (isset($processedPath) && $processedPath !== $fullPath && file_exists($processedPath)) {
        @unlink($processedPath);
      }
    }
  }

  /**
  * Preview transaksi statement.
  */
  public function previewStatement(int $userId, int $statementId): array
  {
    $statement = BankStatement::where('user_id', $userId)->findOrFail($statementId);

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

    $categories = Category::active()
    ->orderBy('name')
    ->get(['id', 'name', 'type', 'icon', 'color']);

    return [
      'statement_id' => $statement->id,
      'wallet' => [
        'id' => $statement->wallet->id,
        'name' => $statement->wallet->name,
      ],
      'transactions' => $transactions,
      'categories' => $categories,
    ];
  }

  /**
  * Import transaksi statement terpilih.
  */
  public function importStatement(int $userId, int $statementId, array $transactionIds): array
  {
    $statement = BankStatement::where('user_id', $userId)->findOrFail($statementId);
    $wallet = $statement->wallet;

    $transactions = $statement->transactions()
    ->notImported()
    ->whereIn('id', $transactionIds)
    ->orderBy('transaction_date', 'asc')
    ->orderBy('id', 'asc')
    ->get();

    if ($transactions->isEmpty()) {
      throw new \Exception('Tidak ada transaksi yang valid untuk diimpor.');
    }

    $imported = 0;
    $skipped = 0;
    $skippedReasons = [];

    DB::beginTransaction();
    try {
      foreach ($transactions as $trx) {
        $transactionType = $trx->type?->toTransactionType();
        if (!$transactionType) {
          $skipped++;
          $skippedReasons[] = "{$trx->description}: tipe tidak dikenali";
          continue;
        }

        $wallet->refresh();

        if ($transactionType === TransactionType::EXPENSE) {
          if ($wallet->balance->isLessThan($trx->amount)) {
            \Log::warning("Saldo kurang.", ["amount" => $trx->getFormattedAmount(), "saldo" => $wallet->getFormattedBalance()]);
            $skipped++;
            $skippedReasons[] = "{$trx->description}: saldo tidak mencukupi (butuh {$trx->getFormattedAmount()}, saldo {$wallet->getFormattedBalance()})";
            continue;
          }
        }

        $this->transactionService->createTransaction(
          (object) ['id' => $userId], // Adapt user object sesuai kebutuhan
          [
            'wallet_id' => $wallet->id,
            'category_id' => $trx->category_id,
            'type' => $transactionType->value,
            'amount' => $trx->amount->getAmount()->toFloat(),
            'transaction_date' => $trx->transaction_date,
            'description' => $trx->description,
            'metadata' => ['imported_from_statement_id' => $statement->id],
          ]
        );

        $trx->markAsImported();
        $imported++;
      }

      $remaining = $statement->transactions()->notImported()->count();
      if ($remaining === 0) {
        $statement->updateStatus(StatementStatus::IMPORTED);
      }

      DB::commit();

      return [
        'imported' => $imported,
        'skipped' => $skipped,
        'skipped_reasons' => $skippedReasons,
      ];
    } catch (\Exception $e) {
      DB::rollBack();
      \Log::error("Failed to import statement.", [
        "message" => $e->getMessage(),
        "trace" => $e->getTraceAsString()
      ]);
      throw $e;
    }
  }

  /**
  * Update kategori transaksi statement.
  */
  public function updateTransactionCategory(int $userId, int $transactionId, int $categoryId): array
  {
    $transaction = StatementTransaction::findOrFail($transactionId);
    if ($transaction->statement->user_id !== $userId) {
      throw new \Exception('Unauthorized');
    }

    $transaction->category_id = $categoryId;
    $transaction->save();

    return [
      'id' => $transaction->id,
      'category' => [
        'id' => $transaction->category->id,
        'name' => $transaction->category->name,
        'icon' => $transaction->category->icon,
        'color' => $transaction->category->color,
      ]
    ];
  }
}