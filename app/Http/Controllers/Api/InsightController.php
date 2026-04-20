<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\InsightService;

class InsightController extends Controller
{
  protected InsightService $insightService;

  public function __construct(InsightService $insightService) {
    $this->insightService = $insightService;
  }

  /**
  * Ringkasan analisis pengeluaran.
  */
  public function summary(): JsonResponse
  {
    $userId = request()->user()->id;
    $data = $this->insightService->getExpenseSummary($userId);

    return response()->json([
      'success' => true,
      'data' => $data
    ]);
  }
}