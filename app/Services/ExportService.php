<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Transfer;
use Modules\FinTech\Models\Budget;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Exports\DataExport;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportService
{
  protected int $maxRecords = 5000;

  /**
  * Generate file export, return path to temp file.
  */
  public function generate(array $filters): string
  {
    $type = $filters['type'];
    $format = $filters['format'];
    $walletId = $filters['wallet_id'];

    // Ambil aturan format dari wallet yang dipilih
    $formatRules = $this->getCurrencyFormat($walletId);

    // Ambil data dan summary (hanya angka mentah)
    [$data,
      $summary] = match ($type) {
      'transactions' => $this->getTransactionsData($filters),
      'transfers' => $this->getTransfersData($filters),
      'budgets' => $this->getBudgetsData($filters),
    };

    $metadata = $this->buildMetadata($type, $filters, $walletId);
    $summary['metadata'] = $metadata;

    // Gabungkan aturan format ke summary
    $summary = array_merge($summary, $formatRules);

    // Cek batas
    if (count($data) > $this->maxRecords) {
      throw new \Exception("Jumlah data ({count($data)}) melebihi batas maksimal ({$this->maxRecords}). Silakan persempit filter.");
    }

    $extension = $format === 'pdf' ? 'pdf' : 'xlsx';
    $filename = Str::uuid() . '.' . $extension;
    $tempPath = "temp/exports/{$filename}";

    if ($format === 'xlsx') {
      $this->generateExcel($type, $data, $summary, $tempPath);
    } else {
      $this->generatePdf($type, $data, $summary, $tempPath);
    }

    return Storage::disk('local')->path($tempPath);
  }

  // ----- Data Retrieval -----

  protected function getTransactionsData(array $filters): array
  {
    $user = request()->user();
    $query = Transaction::with(['wallet', 'category'])
    ->whereHas('wallet', fn($q) => $q->where('user_id', $user->id));

    $this->applyTransactionFilters($query, $filters);

    $transactions = $query->orderBy('transaction_date', 'desc')
    ->limit($this->maxRecords + 1)
    ->get();

    $totalIncome = 0;
    $totalExpense = 0;

    $data = $transactions->map(function ($trx) use (&$totalIncome, &$totalExpense) {
      $amount = $trx->getAmountFloat();
      if ($trx->type === \Modules\FinTech\Enums\TransactionType::INCOME) {
        $totalIncome += $amount;
      } else {
        $totalExpense += $amount;
      }

      return [
        'Tanggal' => $trx->transaction_date->format('d/m/Y'),
        'Tipe' => $trx->type->label(),
        'Kategori' => $trx->category->name,
        'Dompet' => $trx->wallet->name,
        'Jumlah' => $trx->getFormattedAmount(),
        // pakai trait
        'Deskripsi' => $trx->description ?? '-',
      ];
    })->toArray();

    return [
      $data,
      [
        'total_income' => $totalIncome,
        'total_expense' => $totalExpense,
        'net' => $totalIncome - $totalExpense,
      ]
    ];
  }

  protected function getTransfersData(array $filters): array
  {
    $user = request()->user();
    $query = Transfer::with(['fromWallet',
      'toWallet'])
    ->whereHas('fromWallet',
      fn($q) => $q->where('user_id', $user->id));

    $this->applyTransferFilters($query,
      $filters);

    $transfers = $query->orderBy('transfer_date',
      'desc')
    ->limit($this->maxRecords + 1)
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
        // pakai trait
        'Deskripsi' => $t->description ?? '-',
      ];
    })->toArray();

    return [$data, ['total' => $total]];
  }

  protected function getBudgetsData(array $filters): array
  {
    $user = request()->user();
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

    $budgets = $query->get()->filter(function ($budget) use ($filters) {
      if (!empty($filters['status'])) {
        return match ($filters['status']) {
          'overspent' => $budget->isOverspent(),
          'near_limit' => $budget->isNearLimit(),
          'on_track' => !$budget->isOverspent() && !$budget->isNearLimit(),
          default => true,
          };
        }
        return true;
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
          // pakai trait
          'Pengeluaran' => $b->formatCurrency($spent),
          // pakai trait
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
        ]
      ];
    }

    // ----- Filter helpers (tidak berubah) -----

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
        $walletId = $filters['wallet_id'];
        $query->where(function ($q) use ($walletId) {
          $q->where('from_wallet_id', $walletId)
          ->orWhere('to_wallet_id', $walletId);
        });
      }
      if (!empty($filters['month'])) {
        $query->whereYear('transfer_date', substr($filters['month'], 0, 4))
        ->whereMonth('transfer_date', substr($filters['month'], 5, 2));
      } elseif (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        $query->when(!empty($filters['date_from']), fn($q) => $q->where('transfer_date', '>=', $filters['date_from']))
        ->when(!empty($filters['date_to']), fn($q) => $q->where('transfer_date', '<=', $filters['date_to']));
      }
    }

    // ----- Excel & PDF Generation -----

    protected function generateExcel(string $type, array $data, array $summary, string $storagePath): void
    {
      Excel::store(
        new DataExport($type, $data, $summary),
        $storagePath,
        'local',
        ExcelFormat::XLSX
      );
    }

    protected function generatePdf(string $type, array $data, array $summary, string $storagePath): void
    {
      $html = view("fintech::exports.{$type}_pdf", [
        'data' => $data,
        'title' => $this->getTitle($type),
        'summary' => $summary
      ])->render();

      $dompdf = new \Dompdf\Dompdf();
      $dompdf->loadHtml($html);
      $dompdf->setPaper('A4', 'landscape');
      $dompdf->render();

      Storage::disk('local')->put($storagePath, $dompdf->output());
    }

    private function getTitle(string $type): string
    {
      return match ($type) {
        'transactions' => 'Laporan Transaksi',
        'transfers' => 'Laporan Transfer',
        'budgets' => 'Laporan Budget',
      };
    }

    // ----- Currency Format Helpers -----

    /**
    * Get full currency formatting rules for a wallet.
    */
    protected function getCurrencyFormat(int $walletId): array
    {
      $wallet = Wallet::with('currencyDetails')->find($walletId);

      $default = [
        'precision' => 0,
        'decimal_mark' => ',',
        'thousands_separator' => '.',
        'symbol' => 'Rp',
        'symbol_first' => true,
      ];

      if ($wallet && $wallet->currencyDetails) {
        $currency = $wallet->currencyDetails;
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

    protected function buildMetadata(string $type, array $filters, int $walletId): array
    {
      $wallet = Wallet::with('currencyDetails')->findOrFai($walletId);
      $walletName = $wallet->name;

      $meta = [
        'Dompet: ' . $walletName,
        'Tipe Data: ' . $this->getTitle($type),
        'Tanggal Ekspor: ' . now()->format('d M Y H:i'),
      ];

      if (!empty($filters['month'])) {
        $meta[] = 'Periode Bulan: ' . date('F Y', strtotime($filters['month'] . '-01'));
      } elseif (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        $from = !empty($filters['date_from']) ? $filters['date_from'] : 'Awal';
        $to = !empty($filters['date_to']) ? $filters['date_to'] : 'Akhir';
        $meta[] = 'Rentang Tanggal: ' . $from . ' s/d ' . $to;
      }

      if (!empty($filters['transaction_type'])) {
        $meta[] = 'Tipe Transaksi: ' . ($filters['transaction_type'] === 'income' ? 'Pemasukan' : 'Pengeluaran');
      }
      // bisa tambahkan filter lain (kategori, dll.)

      return $meta;
    }
  }