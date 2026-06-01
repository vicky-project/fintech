<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\ExportService;

class ExportController extends Controller
{
  use ResolvesTelegramUser;

  protected ExportService $exportService;

  public function __construct(ExportService $exportService) {
    $this->exportService = $exportService;
  }

  public function index(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $wallets = app(WalletService::class)->getUserWallets($telegramUser);
    $categories = \Modules\FinTech\Models\Category::active()->orderBy('name')->get();

    return view('fintech::web.export.index', compact('wallets', 'categories'));
  }

  public function export(Request $request) {
    $filters = $request->only([
      'type', 'format', 'wallet_id', 'date_from', 'date_to',
      'month', 'year', 'transaction_type', 'category_ids',
      'include_description', 'include_chart', 'include_monthly_summary',
      'include_top5', 'period_type', 'status'
    ]);

    // Pastikan wallet_id ada
    if (empty($filters['wallet_id'])) {
      return back()->with('error', 'Dompet harus dipilih.');
    }

    try {
      $filePath = $this->exportService->generate($filters);

      // Jika Google Sheets, redirect ke URL
      if (($filters['format'] ?? 'xlsx') === 'gsheet') {
        return redirect($filePath);
      }

      $extension = pathinfo($filePath, PATHINFO_EXTENSION);
      $mime = match ($extension) {
        'pdf' => 'application/pdf',
        'csv' => 'text/csv',
        default => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };

        return response()->download($filePath, 'export.' . $extension, ['Content-Type' => $mime])
        ->deleteFileAfterSend();
      } catch (\Exception $e) {
        return back()->with('error', 'Gagal mengekspor: ' . $e->getMessage());
      }
    }
  }