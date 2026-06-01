<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\ReportService;

class ReportController extends Controller
{
  use ResolvesTelegramUser;

  protected ReportService $reportService;

  public function __construct(ReportService $reportService) {
    $this->reportService = $reportService;
  }

  public function index(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);

    $periodType = $request->input('period_type', 'monthly');
    $year = $request->input('year', now()->year);
    $month = $request->input('month', now()->month);
    $walletId = $request->input('wallet_id');

    // Ambil data chart utama
    $chartData = match ($periodType) {
      'yearly' => $this->reportService->getYearlyReport($request, $telegramUser->id),
      'all_years' => $this->reportService->getAllYearsReport($request, $telegramUser->id),
      default => $this->reportService->getMonthlyReport($request, $telegramUser->id),
      };

      // Ambil ringkasan kategori
      $categorySummary = $this->reportService->getCategorySummary($request, $telegramUser->id);
      $categoryTable = $this->reportService->getCategoryTable($request, $telegramUser->id);

      $wallets = app(WalletService::class)->getUserWallets($telegramUser);

      return view('fintech::web.reports.index', compact(
        'chartData',
        'categorySummary',
        'categoryTable',
        'wallets',
        'periodType',
        'year',
        'month',
        'walletId'
      ));
    }
  }