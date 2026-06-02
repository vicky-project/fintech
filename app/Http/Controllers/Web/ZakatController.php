<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\ZakatTaxService;
use Modules\FinTech\Traits\ResolvesTelegramUser;
use Illuminate\Support\Facades\Log;

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

    try {
      $data = $this->zakatTaxService->getDashboardData($telegramUser, (int) $year);
      $error = null;
    } catch (\Exception $e) {
      Log::warning('Zakat dashboard error: ' . $e->getMessage(), [
        'telegram_user_id' => $telegramUser->id,
        'telegram_id' => $request->telegram_id,
      ]);

      $data = null;
      $error = 'Data pengaturan (mata uang default, status perkawinan, tanggungan) belum lengkap. '
      . 'Silakan <a href="' . route('fintech.settings') . '" class="alert-link">lengkapi pengaturan</a> terlebih dahulu.';
    }

    return view('fintech::web.zakat.index', compact('data', 'year', 'error'));
  }
}