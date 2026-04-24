<?php

namespace Modules\FinTech\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\FinTech\Models\BankStatement;
use Modules\FinTech\Models\StatementTransaction;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Models\Category;
use Modules\FinTech\Enums\StatementStatus;
use Modules\FinTech\Enums\StatementType;
use Modules\FinTech\Enums\TransactionType;
use Modules\FinTech\Services\Decryptors\PdfDecryptor;
use Modules\FinTech\Services\Decryptors\ExcelDecryptor;
use Carbon\Carbon;

class StatementService
{
  protected PdfDecryptor $pdfDecryptor;
  protected ExcelDecryptor $excelDecryptor;
  protected BankParserManager $parserManager;
  protected CategorizationService $categorizationService;
  protected TransactionService $transactionService;

  public function __construct(
    PdfDecryptor $pdfDecryptor,
    ExcelDecryptor $excelDecryptor,
    BankParserManager $parserManager,
    CategorizationService $categorizationService,
    TransactionService $transactionService
  ) {
    $this->pdfDecryptor = $pdfDecryptor;
    $this->excelDecryptor = $excelDecryptor;
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
      'processed_at' => $s->processed_at?->setTimezone(config('app.timezone'))->toDateTimeString(),
      'created_at' => $s->created_at->setTimezone(config('app.timezone'))->toDateTimeString(),
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

    $originalName = $file->getClientOriginalName();
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
    $safeName = preg_replace('/_+/', '_', $safeName);

    $tempPath = $file->storeAs(
      'temp/statements/' . $userId,
      uniqid() . '_' . $safeName
    );

    $fullPath = Storage::path($tempPath);
    $processedPath = $fullPath;

    try {
      $statement = BankStatement::create([
        'user_id' => $userId,
        'wallet_id' => $walletId,
        'original_filename' => $file->getClientOriginalName(),
        'file_path' => $tempPath,
        'status' => StatementStatus::UPLOADED,
      ]);

      $extension = strtolower($file->getClientOriginalExtension());
      if ($extension === 'pdf' && $this->pdfDecryptor->isEncrypted($fullPath)) {
        if (!$password) {
          throw new \Exception("File PDF ini diproteksi password. Silakan masukkan password.");
        }
        $processedPath = $this->pdfDecryptor->decrypt($fullPath, $password);
        $statement->updateStatus(StatementStatus::DECRYPTED);
      }

      if (in_array($extension, ['xls', 'xlsx']) && $this->excelDecryptor->isEncrypted($fullPath)) {
        if (!$password) {
          throw new \Exception("File Excel ini diproteksi password.Silakan masukkan password.");
        }
        $processedPath = $this->excelDecryptor->decrypt($fullPath, $password);
        $statement->updateStatus(StatementStatus::DECRYPTED);
      }

      $result = $this->parserManager->parse($processedPath);

      $statement->update([
        'bank_code' => $result['bank_code'],
        'meta_data' => ['transaction_count' => count($result['transactions'])],
      ]);
      $statement->updateStatus(StatementStatus::PARSED);

      // Kumpulkan semua deskripsi unik
      $uniqueDescriptions = [];
      foreach ($result['transactions'] as $item) {
        $desc = $item['description'];
        if (!isset($uniqueDescriptions[$desc])) {
          $uniqueDescriptions[$desc] = [
            'type' => $item['type'] ?? StatementType::fromDescription($desc, $item['amount']),
          ];
        }
      }

      // Kategorisasi setiap deskripsi unik (sekali saja)
      $categoryCache = [];
      foreach ($uniqueDescriptions as $desc => $info) {
        $categoryCache[$desc] = $this->categorizationService->categorize($desc, $info['type'])?->id;
      }

      $insertData = [];
      $now = now();
      foreach ($result['transactions'] as $trx) {
        $desc = $trx['description'];
        $type = $uniqueDescriptions[$desc]['type'];
        $categoryId = $categoryCache[$desc];

        $insertData[] = [
          'statement_id' => $statement->id,
          'transaction_date' => Carbon::create($trx['date'])->format('Y-m-d'),
          'description' => $desc,
          // insert harus mengkalikan manual untuk nilai sen/float
          'amount' => (int) $trx['amount'] * 100,
          'type' => $type,
          'category_id' => $categoryId,
          // insert harus encode manual
          'raw_data' => json_encode($trx),
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
      'date' => $trx->transaction_date->setTimezone(config('app.timezone'))->toFormattedDateString(),
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
  public function importStatement(
    Authenticatable $user,
    BankStatement $statement,
    array $transactionIds
  ): array {
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

        try {
          $this->transactionService->createTransaction(
            $user,
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
        } catch (\Exception $e) {
          $skipped++;
          $skippedReasons[] = "{$trx->description}: {$e->getMessage()}";
        }
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