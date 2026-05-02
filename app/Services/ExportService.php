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
use Modules\FinTech\Services\Google\GoogleSheetsService;
use Modules\FinTech\Services\Google\SpreadsheetManager;

class ExportService
{
  protected int $maxExcelRecords = 5000;
  protected int $maxPdfRecords = 500;

  public function __construct(protected SpreadsheetManager $spreadsheetManager) {}

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
      $includeTop5 = $filters['include_top5'] ?? true;
      $includeCategoryExpense = $filters['include_category_expense'] ?? true;

      foreach (['transactions', 'transfers', 'budgets'] as $subType) {
        $summaryArr = array_merge(
          $result[$subType][1],
          $formatRules,
          ['metadata' => $this->buildMetadata($subType, $filters, $wallet->name)]
        );
        if ($subType === 'transactions') {
          $summaryArr['include_chart'] = $includeChart;
          $summaryArr['include_monthly_summary'] = $includeMonthly;
          $summaryArr['include_top5'] = $includeTop5;
          $summaryArr['include_category_expense'] = $includeCategoryExpense;
        }

        if ($subType === 'transactions' || $subType === 'transfers') {
          $summaryArr['include_description'] = $filters['include_description'] ?? true;
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
    $summary['include_description'] = $filters['include_description'] ?? false;
    $summary['include_chart'] = $filters['include_chart'] ?? false;
    $summary['include_monthly_summary'] = $filters['include_monthly_summary'] ?? false;
    $summary['include_top5'] = $filters['include_top5'] ?? false;
    $summary['include_category_expense'] = $filters['include_category_expense'] ?? false;
    $summary = array_merge($summary, $formatRules, compact('metadata'));

    return match ($format) {
      'pdf' => PdfDataExport::generate($type, $data, $summary),
      'xlsx' => $this->storeXlsx(new ExcelDataExport($type, $data, $summary)),
      'csv' => $this->generateCsv($type, $data, $filters),
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
        $income = $amount;
        $expense = 0;
      } else {
        $totalExpense += $amount;
        $income = 0;
        $expense = $amount;
      }

      return [
        'Tanggal' => $trx->transaction_date->format('d/m/Y'),
        'Tipe' => $trx->type->label(),
        'Kategori' => $trx->category->name,
        'Dompet' => $trx->wallet->name,
        'Pemasukan' => $income,
        // float
        'Pengeluaran' => $expense,
        // float
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
        'Jumlah' => $amount,
        // float
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
          'Limit' => $limit,
          // float
          'Pengeluaran' => $spent,
          // float
          'Persentase' => $b->getPercentage() . '%',
          'Status' => $b->isOverspent() ? 'Terlampaui' : ($b->isNearLimit() ? 'Mendekati' : 'Aman'),
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

      // Hapus semua sheet lama agar tidak menumpuk
      $this->spreadsheetManager->removeSheetsByPrefix($spreadsheetId, 'Transaksi ');
      $this->spreadsheetManager->removeSheetsByPrefix($spreadsheetId, 'Transfer ');
      // Budget tidak dihapus karena hanya satu sheet
      $this->spreadsheetManager->removeSheetsByPrefix($spreadsheetId, 'Budget'); // opsional

      $formatRules = $this->getCurrencyFormat($wallet);

      if ($type === 'all') {
        $all = $this->fetchData('all', $user, $filters, $limit);

        // 1. Transaksi per tahun
        if (!empty($all['transactions'][0])) {
          $this->exportTransactionsPerYear(
            $googleService, $spreadsheetId, $all['transactions'][0], $filters, $wallet, $formatRules
          );
        }

        // 2. Transfer per tahun
        if (!empty($all['transfers'][0])) {
          $this->exportTransfersPerYear(
            $googleService, $spreadsheetId, $all['transfers'][0], $filters, $wallet, $formatRules
          );
        }

        // 3. Budget (satu sheet)
        $this->exportBudgets(
          $googleService, $spreadsheetId, $all['budgets'][0], $filters, $wallet, $formatRules
        );

      } else {
        [$data,
          $summary] = $this->fetchData($type, $user, $filters, $limit);

        switch ($type) {
        case 'transactions':
          if (!empty($data)) {
            $this->exportTransactionsPerYear(
              $googleService, $spreadsheetId, $data, $filters, $wallet, $formatRules
            );
          }
          break;
        case 'transfers':
          if (!empty($data)) {
            $this->exportTransfersPerYear(
              $googleService, $spreadsheetId, $data, $filters, $wallet, $formatRules
            );
          }
          break;
        case 'budgets':
          $this->exportBudgets(
            $googleService, $spreadsheetId, $data, $filters, $wallet, $formatRules
          );
          break;
        }
      }

      return $googleService->getSpreadsheetUrl($spreadsheetId);
    }


    // Generate CSV
    protected function generateCsv(string $type, array $data, array $filters): string
    {
      $includeDesc = ($type === 'transactions' || $type === 'transfers')
      ? ($filters['include_description'] ?? true)
      : true;

      if (!$includeDesc) {
        // Hapus kolom 'Deskripsi' dari setiap baris
        $data = array_map(function ($row) {
          unset($row['Deskripsi']);
          return $row;
        }, $data);
      }

      return $this->storeExport(new CsvDataExport($data), 'csv');
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
        'Tanggal Ekspor: ' . now()->setTimezone(config('app.timezone'))->format('d M Y H:i'),
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

    // ─── Helper untuk export per tahun. Untuk Googlesheet ─────────────────────────────

    /**
    * Ekspor transaksi dikelompokkan per tahun.
    */
    private function exportTransactionsPerYear(
      GoogleSheetsService $googleService,
      string $spreadsheetId,
      array $transactions,
      array $filters,
      Wallet $wallet,
      array $formatRules
    ): void {
      // Kelompokkan berdasarkan tahun
      $grouped = [];
      foreach ($transactions as $row) {
        $date = \DateTime::createFromFormat('d/m/Y', $row['Tanggal'] ?? '');
        if (!$date) continue;
        $year = $date->format('Y');
        $grouped[$year][] = $row;
      }

      foreach ($grouped as $year => $yearData) {
        $sheetName = SpreadsheetManager::SHEET_TRANSACTIONS . ' ' . $year;

        // Hitung ulang summary untuk tahun ini
        $totalIncome = 0;
        $totalExpense = 0;
        foreach ($yearData as $r) {
          $totalIncome += (float)($r['Pemasukan'] ?? 0);
          $totalExpense += (float)($r['Pengeluaran'] ?? 0);
        }
        $yearSummary = [
          'total_income' => $totalIncome,
          'total_expense' => $totalExpense,
          'net' => $totalIncome - $totalExpense,
        ];

        $metadata = $this->buildMetadata('transactions', $filters, $wallet->name);
        $metadata[] = 'Tahun: ' . $year;

        $summary = array_merge($yearSummary, $formatRules, [
          'metadata' => $metadata,
          'include_description' => $filters['include_description'] ?? true,
          'include_monthly_summary' => $filters['include_monthly_summary'] ?? true,
          'include_top5' => $filters['include_top5'] ?? true,
          'include_chart' => $filters['include_chart'] ?? true,
          'include_category_expense' => $filters['include_category_expense'] ?? true,
        ]);

        $googleService->exportDataToSheet(
          $spreadsheetId, $sheetName, $yearData, true, $metadata, $summary, 'transactions', $yearData
        );
      }
    }

    /**
    * Ekspor transfer dikelompokkan per tahun.
    */
    private function exportTransfersPerYear(
      GoogleSheetsService $googleService,
      string $spreadsheetId,
      array $transfers,
      array $filters,
      Wallet $wallet,
      array $formatRules
    ): void {
      $grouped = [];
      foreach ($transfers as $row) {
        $date = \DateTime::createFromFormat('d/m/Y', $row['Tanggal'] ?? '');
        if (!$date) continue;
        $year = $date->format('Y');
        $grouped[$year][] = $row;
      }

      foreach ($grouped as $year => $yearData) {
        $sheetName = SpreadsheetManager::SHEET_TRANSFERS . ' ' . $year;

        // Total transfer untuk tahun ini
        $total = 0;
        foreach ($yearData as $r) {
          $total += (float)($r['Jumlah'] ?? 0);
        }

        $metadata = $this->buildMetadata('transfers', $filters, $wallet->name);
        $metadata[] = 'Tahun: ' . $year;

        $summary = [
          'total' => $total,
          'metadata' => $metadata,
          'include_description' => $filters['include_description'] ?? true,
        ];
        $summary = array_merge($summary, $formatRules);

        $googleService->exportDataToSheet(
          $spreadsheetId, $sheetName, $yearData, true, $metadata, $summary, 'transfers'
        );
      }
    }

    /**
    * Ekspor budget (satu sheet).
    */
    private function exportBudgets(
      GoogleSheetsService $googleService,
      string $spreadsheetId,
      array $budgets,
      array $filters,
      Wallet $wallet,
      array $formatRules
    ): void {
      $sheetName = SpreadsheetManager::SHEET_BUDGETS;
      $metadata = $this->buildMetadata('budgets', $filters, $wallet->name);

      $summary = [
        'total_limit' => 0,
        'total_spent' => 0,
        'remaining' => 0,
      ];
      if (!empty($budgets)) {
        // Hitung summary dari data budget yang sudah di-fetch
        $totalLimit = 0;
        $totalSpent = 0;
        foreach ($budgets as $b) {
          $totalLimit += (float)($b['Limit'] ?? 0);
          $totalSpent += (float)($b['Pengeluaran'] ?? 0);
        }
        $summary = [
          'total_limit' => $totalLimit,
          'total_spent' => $totalSpent,
          'remaining' => $totalLimit - $totalSpent,
        ];
      }

      $summary = array_merge($summary, $formatRules, ['metadata' => $metadata]);

      $googleService->exportDataToSheet(
        $spreadsheetId, $sheetName, $budgets, true, $metadata, $summary, 'budgets'
      );
    }
  }