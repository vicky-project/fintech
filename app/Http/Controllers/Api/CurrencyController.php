<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nnjeim\World\Models\Currency;

class CurrencyController extends Controller
{
  /**
  * Get list of all currencies for dropdown.
  */
  public function index(): JsonResponse
  {
    $currencies = Currency::select('code', 'name', 'symbol', 'precision')
    ->orderBy('name')
    ->get();

    return response()->json([
      'success' => true,
      'data' => $currencies
    ]);
  }
}