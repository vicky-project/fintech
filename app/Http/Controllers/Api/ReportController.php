<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\ReportService;

class ReportController extends Controller
{
  protected ReportService $reportService;

  public function __construct(ReportService $reportService) {
    $this->reportService = $reportService;
  }

  /**
  * Weekly report (bar chart).
  */
  public function weekly(Request $request): JsonResponse
  {
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'year' => 'integer|min:2000|max:2100',
      'week' => 'integer|min:1|max:53'
    ]);

    $data = $this->reportService->getWeeklyReport($request, $request->user()->id);

    return response()->json([
      'success' => true,
      'data' => $data
    ]);
  }

  /**
  * Monthly report (bar chart).
  */
  public function monthly(Request $request): JsonResponse
  {
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'year' => 'integer|min:2000|max:2100',
      'month' => 'integer|min:1|max:12'
    ]);

    $data = $this->reportService->getMonthlyReport($request, $request->user()->id);

    return response()->json([
      'success' => true,
      'data' => $data
    ]);
  }

  /**
  * Yearly report (bar chart).
  */
  public function yearly(Request $request): JsonResponse
  {
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'year' => 'integer|min:2000|max:2100'
    ]);

    $data = $this->reportService->getYearlyReport($request, $request->user()->id);

    return response()->json([
      'success' => true,
      'data' => $data
    ]);
  }

  /**
  * Doughnut chart for weekly expenses by category.
  */
  public function doughnutWeekly(Request $request): JsonResponse
  {
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'week_offset' => 'integer|min:0'
    ]);

    $data = $this->reportService->getDoughnutWeeklyReport($request, $request->user()->id);

    return response()->json([
      'success' => true,
      'data' => $data
    ]);
  }
}