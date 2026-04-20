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

  public function fullAnalysis(): JsonResponse
  {
    $userId = request()->user()->id;
    $data = $this->insightService->getFullAnalysis($userId);

    return response()->json([
      'success' => true,
      'data' => $data
    ]);
  }
}