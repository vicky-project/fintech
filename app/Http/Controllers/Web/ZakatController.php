<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\ZakatTaxService;

class ZakatController extends Controller
{
  use ResolvesTelegramUser;

  protected ZakatTaxService $zakatTaxService;

  public function __construct(ZakatTaxService $zakatTaxService) {
    $this->zakatTaxService = $zakatTaxService;
  }

  public function index(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $year = $request->input('year', now()->year);
    $data = $this->zakatTaxService->getDashboardData($telegramUser, (int) $year);

    return view('fintech::web.zakat.index', [
      'data' => $data,
      'year' => $year,
    ]);
  }
}