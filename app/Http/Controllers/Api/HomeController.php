<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Modules\FinTech\Services\HomeService;

class HomeController extends Controller
{
  public function index(Request $request, HomeService $service): JsonResponse
  {
    $user = $request->user();
    abort_if(!$user, 401);

    return response()->json([
      'success' => true,
      'data' => $service->getHomeData($user)
    ]);
  }

  public function monthlyComparison(Request $request, HomeService $service): JsonResponse
  {
    $user = $request->user();
    if (!$user) {
      abort(401);
    }

    return response()->json([
      'success' => true,
      'data' => $service->getMonthlyComparisonData($user->id),
    ]);
  }
}