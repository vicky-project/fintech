<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Transfer;
use Modules\FinTech\Models\Budget;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Enums\TransactionType;
use Modules\FinTech\Exports\AllExcelDataExport;
use Modules\FinTech\Exports\CsvDataExport;
use Modules\FinTech\Exports\ExcelDataExport;
use Modules\FinTech\Exports\PdfDataExport;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\FinTech\Services\Google\ {
  GoogleSheetsService,
  SpreadsheetManager
};

class ExportService
{
  protected int $maxExcelRecords = 5000;
  protected int $maxPdfRecords = 500;

  /**
  * Generate export – mengembalikan path file atau URL (untuk gsheet).
  */
  public function generate(array $filters): string
  {
    $type = $filters['type'];
    $format = $filters['format'];
    $wallet = Wallet::with('currencyDetails')->findOrFail($filters['wallet_id']);
    $user = request()->user();

    // Validasi format ilegal untuk all
    if ($type === 'all' && in_array($format, ['pdf', 'csv'])) {
      throw new \Exception("Format {$format} tidak tersedia untuk semua data. Gunakan Excel atau Google Sheets.");
    }

    // Google Sheets menggunakan jalur sendiri karena tidak menghasilkan file lokal
    if ($format === 'gsheet') {
      return $this->generateGoogleSheets($type, $filters, $user, $wallet);
    }

    // Data + summary
    $limit = $format === 'pdf' ? $this->maxPdfRecords : $this->maxExcelRecords;
    $result = $this->fetchData($type, $user, $filters, $limit);
    $formatRules = $this->getCurrencyFormat($wallet);

    if ($type === 'all') {
      $includeChart = $filters['include_chart'] ?? true;
      $includeMonthly = $filters['include_monthly_summary'] ?? true;
      $includeTopSpending = $filters['include_top_spending'] ?? true;

      foreach (['transactions', 'transfers', 'budgets'] as $subType) {
        $summaryArr = array_merge(
          $result[$subType][1],
          $formatRules,
          ['metadata' => $this->buildMetadata($subType, $filters, $wallet->name)]
        );
        // Hanya untuk sheet transaksi
        if ($subType === 'transactions') {
          $summaryArr['include_chart'] = $includeChart;
          $summaryArr['include_monthly_summary'] = $includeMonthly;
          $summaryArr['include_top_spending'] = $includeTopSpending;
        }
        $result[$subType][1] = $summaryArr;
      }
      return $this->storeXlsx(new AllExcelDataExport($result));
    }

    // Tipe tunggal
    [$data,
      $summary] = $result;
    if ($format === 'pdf' && count($data) > $this->maxPdfRecords) {
      throw new \Exception(
        "Jumlah data (" . count($data) . ") melebihi batas PDF ({$this->maxPdfRecords}). Silakan gunakan Excel atau persempit filter."
      );
    }

    $metadata = $this->buildMetadata($type, $filters, $wallet->name);
    $summary['include_chart'] = $filters['include_chart'] ?? false;
    $summary['include_monthly_summary'] = $filters['include_monthly_summary'] ?? false;
    $summary['include_top_spending'] = $filters['include_top_spending'] ?? false;
    $summary = array_merge($summary, $formatRules, compact('metadata'));

    return match ($format) {
      'pdf' => PdfDataExport::generate($type, $data, $summary),
      'xlsx' => $this->storeXlsx(new ExcelDataExport($type, $data, $summary)),
      'csv' => $this->storeExport(new CsvDataExport($data), 'csv'),
    };
  }

  // ----------------------------------------------------------------
  // DATA FETCHING
  // ----------------------------------------------------------------

  protected function fetchData(string $type, $user, array $filters, int $limit): array
  {
    if ($type === 'all') {
      return [
        'transactions' => $this->getTransactionsData($user, $filters, $limit),
        'transfers' => $this->getTransfersData($user, $filters, $limit),
        'budgets' => $this->getBudgetsData($user, $filters, $limit),
      ];
    }

    return match ($type) {
      'transactions' => $this->getTransactionsData($user, $filters, $limit),
      'transfers' => $this->getTransfersData($user, $filters, $limit),
      'budgets' => $this->getBudgetsData($user, $filters, $limit),
    };
  }

  // ----------------------------------------------------------------
  // DATA BUILDERS
  // ----------------------------------------------------------------

  protected function getTransactionsData($user, array $filters, int $limit): array
  {
    $query = Transaction::with(['wallet', 'category'])
    ->whereHas('wallet', fn($q) => $q->where('user_id', $user->id));

    $this->applyTransactionFilters($query, $filters);

    $transactions = $query->orderBy('transaction_date', 'desc')
    ->limit($limit + 1)
    ->get();

    $totalIncome = 0;
    $totalExpense = 0;

    $data = $transactions->map(function ($trx) use (&$totalIncome, &$totalExpense) {
      $amount = $trx->getAmountFloat();
      if ($trx->type === TransactionType::INCOME) {
        $totalIncome += $amount;
        $incomeNum = $amount;
        $expenseNum = 0;
      } else {
        $totalExpense += $amount;
        $incomeNum = 0;
        $expenseNum = $amount;
      }

      return [
        'Tanggal' => $trx->transaction_date->format('d/m/Y'),
        'Tipe' => $trx->type->label(),
        'Kategori' => $trx->category->name,
        'Dompet' => $trx->wallet->name,
        'Pemasukan' => $incomeNum,
        'Pengeluaran' => $expenseNum,
        'Deskripsi' => $trx->description ?? '-',
      ];
    })->toArray();

    return [
      $data,
      [
        'total_income' => $totalIncome,
        'total_expense' => $totalExpense,
        'net' => $totalIncome - $totalExpense,
      ],
    ];
  }

  protected function getTransfersData($user,
    array $filters,
    int $limit): array
  {
    $query = Transfer::with(['fromWallet',
      'toWallet'])
    ->whereHas('fromWallet',
      fn($q) => $q->where('user_id', $user->id));

    $this->applyTransferFilters($query,
      $filters);

    $transfers = $query->orderBy('transfer_date',
      'desc')
    ->limit($limit + 1)
    ->get();

    $total = 0;
    $data = $transfers->map(function ($t) use (&$total) {
      $amount = $t->getAmountFloat();
      $total += $amount;
      return [
        'Tanggal' => $t->transfer_date->format('d/m/Y'),
        'Dari' => $t->fromWallet->name,
        'Ke' => $t->toWallet->name,
        'Jumlah' => $t->getFormattedAmount(),
        'Deskripsi' => $t->description ?? '-',
      ];
    })->toArray();

    return [$data, ['total' => $total]];
  }

  protected function getBudgetsData($user,
    array $filters,
    int $limit): array
  {
    $query = Budget::where('user_id',
      $user->id)
    ->with(['category',
      'wallet']);

    if (!empty($filters['period_type'])) {
      $query->where('period_type', $filters['period_type']);
    }
    if (!empty($filters['wallet_id'])) {
      $query->where('wallet_id', $filters['wallet_id']);
    }
    if (!empty($filters['category_ids'])) {
      $query->whereIn('category_id', $filters['category_ids']);
    }

    $budgets = $query->limit($limit + 1)->get()
    ->filter(function ($budget) use ($filters) {
      if (empty($filters['status'])) return true;
      return match ($filters['status']) {
        'overspent' => $budget->isOverspent(),
        'near_limit' => $budget->isNearLimit(),
        'on_track' => !$budget->isOverspent() && !$budget->isNearLimit(),
        default => true,
        };
      });

      $totalLimit = 0;
      $totalSpent = 0;
      $data = $budgets->map(function ($b) use (&$totalLimit, &$totalSpent) {
        $limit = $b->getAmountFloat();
        $spent = $b->getCurrentSpending();
        $totalLimit += $limit;
        $totalSpent += $spent;

        return [
          'Kategori' => $b->category->name,
          'Dompet' => $b->wallet?->name ?? '-',
          'Periode' => $b->period_type->label(),
          'Limit' => $b->getFormattedAmount(),
          'Pengeluaran' => $b->formatCurrency($spent),
          'Persentase' => $b->getPercentage() . '%',
          'Status' => $b->isOverspent()
          ? 'Terlampaui'
          : ($b->isNearLimit() ? 'Mendekati' : 'Aman'),
        ];
      })->values()->toArray();

      return [
        $data,
        [
          'total_limit' => $totalLimit,
          'total_spent' => $totalSpent,
          'remaining' => $totalLimit - $totalSpent,
        ],
      ];
    }

    // ----------------------------------------------------------------
    // FILTER HELPERS
    // ----------------------------------------------------------------

    protected function applyTransactionFilters($query,
      array $filters): void
    {
      if (!empty($filters['wallet_id'])) {
        $query->where('wallet_id', $filters['wallet_id']);
      }
      if (!empty($filters['transaction_type'])) {
        $query->where('type', $filters['transaction_type']);
      }
      if (!empty($filters['month'])) {
        $query->whereYear('transaction_date', substr($filters['month'], 0, 4))
        ->whereMonth('transaction_date', substr($filters['month'], 5, 2));
      } elseif (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        $query->when(!empty($filters['date_from']), fn($q) => $q->where('transaction_date', '>=', $filters['date_from']))
        ->when(!empty($filters['date_to']), fn($q) => $q->where('transaction_date', '<=', $filters['date_to']));
      }
      if (!empty($filters['category_ids'])) {
        $query->whereIn('category_id', $filters['category_ids']);
      }
    }

    protected function applyTransferFilters($query, array $filters): void
    {
      if (!empty($filters['wallet_id'])) {
        $wid = $filters['wallet_id'];
        $query->where(fn($q) => $q->where('from_wallet_id', $wid)->orWhere('to_wallet_id', $wid));
      }
      if (!empty($filters['month'])) {
        $query->whereYear('transfer_date', substr($filters['month'], 0, 4))
        ->whereMonth('transfer_date', substr($filters['month'], 5, 2));
      } elseif (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        $query->when(!empty($filters['date_from']), fn($q) => $q->where('transfer_date', '>=', $filters['date_from']))
        ->when(!empty($filters['date_to']), fn($q) => $q->where('transfer_date', '<=', $filters['date_to']));
      }
    }

    // ----------------------------------------------------------------
    // GOOGLE SHEETS
    // ----------------------------------------------------------------

    protected function generateGoogleSheets(string $type, array $filters, $user, Wallet $wallet): string
    {
      $googleService = app(GoogleSheetsService::class);
      $googleService->setupForUser($user);
      $spreadsheetId = $googleService->getOrCreateSpreadsheet($user);
      $limit = $this->maxExcelRecords;

      $metadata = $this->buildMetadata($type, $filters, $wallet->name);
      $formatRules = $this->getCurrencyFormat($wallet);

      // Data mentah transaksi untuk tabel tambahan
      $rawTransactions = [];

      if ($type === 'all') {
        $all = $this->fetchData('all', $user, $filters, $limit);
        $rawTransactions = $all['transactions'][0] ?? [];

        $metaTx = $this->buildMetadata('transactions', $filters, $wallet->name);
        $metaTf = $this->buildMetadata('transfers', $filters, $wallet->name);
        $metaBg = $this->buildMetadata('budgets', $filters, $wallet->name);

        // Gabungkan flag dan rules ke summary transaksi
        $txSummary = array_merge($all['transactions'][1], $formatRules, [
          'metadata' => $metaTx,
          'include_monthly_summary' => $filters['include_monthly_summary'] ?? true,
          'include_top_spending' => $filters['include_top_spending'] ?? true,
          'include_chart' => $filters['include_chart'] ?? true,
        ]);

        $googleService->exportDataToSheet(
          $spreadsheetId,
          SpreadsheetManager::SHEET_TRANSACTIONS,
          $all['transactions'][0],
          true,
          $metaTx,
          $txSummary,
          'transactions',
          $rawTransactions // <-- kirim data mentah
        );
        $googleService->exportDataToSheet($spreadsheetId, SpreadsheetManager::SHEET_TRANSFERS, $all['transfers'][0], true, $metaTf, $all['transfers'][1], 'transfers');
        $googleService->exportDataToSheet($spreadsheetId, SpreadsheetManager::SHEET_BUDGETS, $all['budgets'][0], true, $metaBg, $all['budgets'][1], 'budgets');
      } else {
        [$data,
          $summary] = $this->fetchData($type, $user, $filters, $limit);
        $sheetName = match ($type) {
          'transactions' => SpreadsheetManager::SHEET_TRANSACTIONS,
          'transfers' => SpreadsheetManager::SHEET_TRANSFERS,
          'budgets' => SpreadsheetManager::SHEET_BUDGETS,
        };

        if ($type === 'transactions') {
          $summary['include_monthly_summary'] = $filters['include_monthly_summary'] ?? false;
          $summary['include_top_spending'] = $filters['include_top_spending'] ?? false;
          $summary['include_chart'] = $filters['include_chart'] ?? false;
          $rawTransactions = $data;
        }

        $summary = array_merge($summary, $formatRules);
        $googleService->exportDataToSheet(
          $spreadsheetId,
          $sheetName,
          $data,
          true,
          $metadata,
          $summary,
          $type,
          $rawTransactions // <-- kirim data mentah
        );
      }

      return $googleService->getSpreadsheetUrl($spreadsheetId);
    }

    // ----------------------------------------------------------------
    // STORAGE HELPERS
    // ----------------------------------------------------------------

    protected function storeXlsx($export): string
    {
      return $this->storeExport($export, 'xlsx');
    }

    protected function storeExport($export, string $extension): string
    {
      $filename = Str::uuid()->toString() . '.' . $extension;
      $tempPath = "temp/exports/{$filename}";
      $writerType = $extension === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;
      Excel::store($export, $tempPath, 'local', $writerType);
      return Storage::disk('local')->path($tempPath);
    }

    // ----------------------------------------------------------------
    // HELPERS
    // ----------------------------------------------------------------

    protected function getCurrencyFormat(Wallet $wallet): array
    {
      $default = [
        'precision' => 0,
        'decimal_mark' => ',',
        'thousands_separator' => '.',
        'symbol' => 'Rp',
        'symbol_first' => true,
      ];

      if ($currency = $wallet->currencyDetails) {
        return [
          'precision' => $currency->precision ?? $default['precision'],
          'decimal_mark' => $currency->decimal_mark ?? $default['decimal_mark'],
          'thousands_separator' => $currency->thousands_separator ?? $default['thousands_separator'],
          'symbol' => $currency->symbol ?? $default['symbol'],
          'symbol_first' => $currency->symbol_first ?? $default['symbol_first'],
        ];
      }

      return $default;
    }

    protected function buildMetadata(string $type, array $filters, string $walletName): array
    {
      $meta = [
        'Dompet: ' . $walletName,
        'Tipe Data: ' . ($type === 'all' ? 'Semua Data' : $this->getTitle($type)),
        'Tanggal Ekspor: ' . now()->format('d M Y H:i'),
      ];

      if (!empty($filters['month'])) {
        $meta[] = 'Periode Bulan: ' . date('F Y', strtotime($filters['month'] . '-01'));
      } elseif (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        $from = $filters['date_from'] ?? 'Awal';
        $to = $filters['date_to'] ?? 'Akhir';
        $meta[] = 'Rentang Tanggal: ' . $from . ' s/d ' . $to;
      }

      if ($type === 'transactions' && !empty($filters['transaction_type'])) {
        $meta[] = 'Tipe Transaksi: ' . ($filters['transaction_type'] === 'income' ? 'Pemasukan' : 'Pengeluaran');
      }

      return $meta;
    }

    protected function getTitle(string $type): string
    {
      return match ($type) {
        'transactions' => 'Laporan Transaksi',
        'transfers' => 'Laporan Transfer',
        'budgets' => 'Laporan Budget',
      };
    }
  }