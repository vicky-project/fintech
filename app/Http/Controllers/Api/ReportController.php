<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\ReportService;
use Carbon\Carbon;

class ReportController extends Controller
{
  protected ReportService $reportService;

  public function __construct(ReportService $reportService) {
    $this->reportService = $reportService;
  }

  /**
  * All years report (total per year).
  */
  public function allYears(Request $request): JsonResponse
  {
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id'
    ]);

    try {
      $data = $this->reportService->getAllYearsReport($request, $request->user()->id);

      return response()->json([
        'success' => true,
        'data' => $data
      ]);
    }catch(\Exception $e) {
      \Log::error("Failed to get report all year", [
        "message" => $e->getMessage(),
        "trace" => $e->getTraceAsString()
      ]);

      return response()->json([
        "success" => false,
        "message" => $e->getMessage()
      ], 500);
    }
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

    try {
      $data = $this->reportService->getWeeklyReport($request, $request->user()->id);

      return response()->json([
        'success' => true,
        'data' => $data
      ]);
    } catch (\Exception $e) {
      \Log::error("Failed to get report weekly", [
        "message" => $e->getMessage(),
        "trace" => $e->getTraceAsString()
      ]);

      return response()->json([
        "success" => false,
        "message" => $e->getMessage()
      ], 500);
    }
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

    try {
      $data = $this->reportService->getMonthlyReport($request, $request->user()->id);

      return response()->json([
        'success' => true,
        'data' => $data
      ]);
    } catch (\Exception $e) {
      \Log::error("Failed to get report monthly", [
        "message" => $e->getMessage(),
        "trace" => $e->getTraceAsString()
      ]);

      return response()->json([
        "success" => false,
        "message" => $e->getMessage()
      ], 500);
    }
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

    try {
      $data = $this->reportService->getYearlyReport($request, $request->user()->id);

      return response()->json([
        'success' => true,
        'data' => $data
      ]);
    } catch(\Exception $e) {
      \Log::error("Failed to get report yearly", [
        "message" => $e->getMessage(),
        "trace" => $e->getTraceAsString()
      ]);

      return response()->json([
        "success" => false,
        "message" => $e->getMessage()
      ], 500);
    }
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

    try {
      $data = $this->reportService->getDoughnutWeeklyReport($request, $request->user()->id);

      return response()->json([
        'success' => true,
        'data' => $data
      ]);
    } catch(\Exception $e) {
      \Log::error("Failed to get report doughnut weekly", [
        "message" => $e->getMessage(),
        "trace" => $e->getTraceAsString()
      ]);

      return response()->json([
        "success" => false,
        "message" => $e->getMessage()
      ], 500);
    }
  }

  /**
  * Category summary report.
  */
  public function categorySummary(Request $request): JsonResponse
  {
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'period_type' => 'in:monthly,yearly,all_years',
      'year' => 'integer|min:2000|max:2100',
      'month' => 'integer|min:1|max:12',
      'type' => 'in:income,expense'
    ]);

    $data = $this->reportService->getCategorySummary($request, $request->user()->id);

    return response()->json([
      'success' => true,
      'data' => $data
    ]);
  }

  /**
  * Category table report.
  */
  public function categoryTable(Request $request): JsonResponse
  {
    $data = $this->reportService->getCategoryTable($request, $request->user()->id);

    return response()->json([
      'success' => true,
      'data' => $data
    ]);
  }
}