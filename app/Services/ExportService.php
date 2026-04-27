<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Transfer;
use Modules\FinTech\Models\Budget;
use Modules\FinTech\Enums\TransactionType;
use Modules\FinTech\Exports\DataExport;
use Modules\FinTech\Services\WalletService;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportService
{
  protected WalletService $walletService;
  protected int $maxRecords = 5000;

  public function __construct(WalletService $walletService) {
    $this->walletService = $walletService;
  }

  /**
  * Generate file export, return path to temp file.
  */
  public function generate(array $filters): string
  {
    $type = $filters['type'];
    $format = $filters['format'];

    // Ambil data
    $data = match ($type) {
      'transactions' => $this->getTransactionsData($filters),
      'transfers' => $this->getTransfersData($filters),
      'budgets' => $this->getBudgetsData($filters),
    };

    if (count($data) > $this->maxRecords) {
      throw new \Exception("Jumlah data ({count($data)}) melebihi batas maksimal ({$this->maxRecords}). Silakan persempit filter.");
    }

    $extension = $format === 'pdf' ? 'pdf' : 'xlsx';
    $filename = Str::uuid() . '.' . $extension;
    $tempPath = "temp/exports/{$filename}";

    if ($format === 'xlsx') {
      $this->generateExcel($type, $data, $tempPath);
    } else {
      $this->generatePdf($type, $data, $tempPath);
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

    return $query->orderBy('transaction_date', 'desc')
    ->limit($this->maxRecords + 1)
    ->get()
    ->map(fn($trx) => [
      'Tanggal' => $trx->transaction_date->format('d/m/Y'),
      'Tipe' => $trx->type->label(),
      'Kategori' => $trx->category->name,
      'Dompet' => $trx->wallet->name,
      'Jumlah' => $trx->getFormattedAmount(),
      'Deskripsi' => $trx->description ?? '-',
    ])->toArray();
  }

  protected function applyTransactionFilters($query, array $filters): void
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

  protected function getTransfersData(array $filters): array
  {
    $user = request()->user();
    $query = Transfer::with(['fromWallet', 'toWallet'])
    ->whereHas('fromWallet', fn($q) => $q->where('user_id', $user->id));

    $this->applyTransferFilters($query, $filters);

    return $query->orderBy('transfer_date', 'desc')
    ->limit($this->maxRecords + 1)
    ->get()
    ->map(fn($t) => [
      'Tanggal' => $t->transfer_date->format('d/m/Y'),
      'Dari' => $t->fromWallet->name,
      'Ke' => $t->toWallet->name,
      'Jumlah' => $t->getFormattedAmount(),
      'Deskripsi' => $t->description ?? '-',
    ])->toArray();
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

  protected function getBudgetsData(array $filters): array
  {
    $user = request()->user();
    $query = Budget::where('user_id', $user->id)
    ->with(['category', 'wallet']);

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

      return $budgets->map(fn($b) => [
        'Kategori' => $b->category->name,
        'Dompet' => $b->wallet?->name ?? '-',
        'Periode' => $b->period_type->label(),
        'Limit' => $b->getFormattedAmount(),
        'Pengeluaran' => 'Rp ' . number_format($b->getCurrentSpending(), 0, ',', '.'),
        'Persentase' => $b->getPercentage() . '%',
        'Status' => $b->isOverspent() ? 'Terlampaui' : ($b->isNearLimit() ? 'Mendekati' : 'Aman'),
      ])->values()->toArray();
    }

    // ----- Excel Generation -----

    protected function generateExcel(string $type,
      array $data,
      string $storagePath): void
    {
      Excel::store(
        new DataExport($type, $data),
        $storagePath,
        'local',
        ExcelFormat::XLSX
      );
    }

    // ----- PDF Generation -----

    protected function generatePdf(string $type,
      array $data,
      string $storagePath): void
    {
      $html = view("fintech::exports.{$type}_pdf",
        ['data' => $data,
          'title' => $this->getTitle($type)])->render();

      $dompdf = new \Dompdf\Dompdf();
      $dompdf->loadHtml($html);
      $dompdf->setPaper('A4',
        'landscape');
      $dompdf->render();

      Storage::disk('local')->put($storagePath,
        $dompdf->output());
    }

    private function getTitle(string $type): string
    {
      return match ($type) {
        'transactions' => 'Laporan Transaksi',
        'transfers' => 'Laporan Transfer',
        'budgets' => 'Laporan Budget',
      };
    }
  }