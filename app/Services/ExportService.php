<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Transfer;
use Modules\FinTech\Models\Budget;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Enums\TransactionType;
use Modules\FinTech\Exports\AllDataExport;
use Modules\FinTech\Exports\CsvDataExport;
use Modules\FinTech\Exports\ExcelDataExport;
use Modules\FinTech\Services\GoogleSheetsService;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportService
{
  protected int $maxExcelRecords = 5000;
  protected int $maxPdfRecords = 500;

  /**
  * Generate file export, return path to temp file.
  */
  public function generate(array $filters): string
  {
    $type = $filters['type'];
    $format = $filters['format'];
    $wallet = Wallet::with('currencyDetails')->findOrFail($filters['wallet_id']);

    $walletName = $wallet->name;
    $formatRules = $this->getCurrencyFormat($wallet);
    $user = request()->user();

    // Tentukan limit dan method data
    $isPdf = $format === 'pdf';
    $isExcel = $format === 'xlsx';
    $limit = $isPdf ? $this->maxPdfRecords : $this->maxExcelRecords;

    // Jika semua tipe data (hanya Excel)
    if ($type === 'all') {
      $this->ensureNotPdf($format);

      // Ambil data per tipe dengan limit
      $transactions = $this->getTransactionsData($user, $filters, $limit);
      $transfers = $this->getTransfersData($user, $filters, $limit);
      $budgets = $this->getBudgetsData($user, $filters, $limit);

      // Metadata per sheet
      $transactions[1] = array_merge($transactions[1], $formatRules, [
        'metadata' => $this->buildMetadata('transactions', $filters, $walletName)
      ]);
      $transfers[1] = array_merge($transfers[1], $formatRules, [
        'metadata' => $this->buildMetadata('transfers', $filters, $walletName)
      ]);
      $budgets[1] = array_merge($budgets[1], $formatRules, [
        'metadata' => $this->buildMetadata('budgets', $filters, $walletName)
      ]);

      $allData = compact('transactions', 'transfers', 'budgets');

      return $this->storeExcel(
        new AllDataExport($allData),
        'xlsx'
      );
    }

    // Tipe tunggal (transactions / transfers / budgets)
    [$data,
      $summary] = match ($type) {
      'transactions' => $this->getTransactionsData($user, $filters, $limit),
      'transfers' => $this->getTransfersData($user, $filters, $limit),
      'budgets' => $this->getBudgetsData($user, $filters, $limit),
    };

    // Cek batas khusus PDF
    if ($isPdf && count($data) > $this->maxPdfRecords) {
      throw new \Exception(
        "Jumlah data (" . count($data) . ") melebihi batas PDF ({$this->maxPdfRecords}). " .
        "Silakan gunakan Excel atau persempit filter."
      );
    }

    $metadata = $this->buildMetadata($type, $filters, $walletName);
    $summary = array_merge($summary, $formatRules, compact('metadata'));

    if ($isPdf) {
      return $this->generatePdf($type, $data, $summary);
    } elseif ($isExcel) {
      return $this->generateExcel($type, $data, $summary);
    } else {
      return $this->generateCsv($data);
    }

  }

  // ----------------------------------------------------------------
  // DATA RETRIEVAL
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
        $income = $trx->getFormattedAmount();
        $expense = '-';
      } else {
        $totalExpense += $amount;
        $income = '-';
        $expense = $trx->getFormattedAmount();
      }

      return [
        'Tanggal' => $trx->transaction_date->format('d/m/Y'),
        'Tipe' => $trx->type->label(),
        'Kategori' => $trx->category->name,
        'Dompet' => $trx->wallet->name,
        'Pemasukan' => $income,
        'Pengeluaran' => $expense,
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
    // GENERATION (EXCEL / PDF / CSV)
    // ----------------------------------------------------------------

    protected function generateExcel(string $type, array $data, array $summary): string
    {
      return $this->storeExport(new ExcelDataExport($type, $data, $summary), 'xlsx');
    }

    protected function generatePdf(string $type, array $data, array $summary): string
    {
      set_time_limit(60);
      ini_set('memory_limit', '256M');

      $html = view("fintech::exports.{$type}_pdf", [
        'data' => $data,
        'title' => $this->getTitle($type),
        'summary' => $summary,
      ])->render();

      $dompdf = new \Dompdf\Dompdf();
      $dompdf->loadHtml($html);
      $dompdf->setPaper('A4', 'landscape');
      $dompdf->render();

      $filename = Str::uuid()->toString() . '.pdf';
      $tempPath = "temp/exports/{$filename}";

      Storage::disk('local')->put($tempPath, $dompdf->output());

      return Storage::disk('local')->path($tempPath);
    }

    protected function generateCsv(array $data): string
    {
      return $this->storeExport(new CsvDataExport($data), 'csv');
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

    protected function ensureNotPdf(string $format): void
    {
      if ($format === 'pdf') {
        throw new \Error(
          "Export PDF tidak tersedia untuk semua data. Gunakan format Excel."
        );
      }
    }

    // GOOGLESHEET
    public function exportToGoogleSheets(array $filters): array
    {
      $user = request()->user();
      $type = $filters['type'];
      $limit = $this->maxExcelRecords; // pakai batas Excel

      [$data,
        $summary] = match ($type) {
        'transactions' => $this->getTransactionsData($user, $filters, $limit),
        'transfers' => $this->getTransfersData($user, $filters, $limit),
        'budgets' => $this->getBudgetsData($user, $filters, $limit),
      };

      $googleSheets = app(GoogleSheetsService::class);
      $spreadsheetId = $googleSheets->getOrCreateSpreadsheet($user);

      $sheetName = match ($type) {
        'transactions' => GoogleSheetsService::SHEET_TRANSACTIONS,
        'transfers' => GoogleSheetsService::SHEET_TRANSFERS,
        'budgets' => GoogleSheetsService::SHEET_BUDGETS,
      };

      $googleSheets->exportDataToSheet($spreadsheetId, $sheetName, $data, true);

      return [
        'url' => $googleSheets->getSpreadsheetUrl($spreadsheetId),
      ];
    }
  }