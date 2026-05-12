<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Modules\FinTech\Services\ZakatTaxService;

class ZakatTaxController extends Controller
{
  protected ZakatTaxService $service;

  public function __construct(ZakatTaxService $service) {
    $this->service = $service;
  }

  public function getDashboard(Request $request) {
    $user = $request->user();
    if (!$user) {
      return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
    }
    $year = (int) $request->input('year', date('Y'));
    $year = max(2020, min($year, date('Y')));
    try {
      $data = $this->service->getDashboardData($user, $year);
      return response()->json(['success' => true, 'data' => $data]);
    } catch (\Exception $e) {
      return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
  }

  public function refreshCache(Request $request) {
    $user = $request->user();
    $this->service->clearUserCache($user->id);
    return response()->json(['success' => true, 'message' => 'Cache berhasil direset']);
  }
}