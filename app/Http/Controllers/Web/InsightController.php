<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\InsightService;
use Modules\FinTech\Traits\ResolvesTelegramUser;

class InsightController extends Controller
{
  use ResolvesTelegramUser;

  protected InsightService $insightService;

  public function __construct(InsightService $insightService) {
    $this->insightService = $insightService;
  }

  public function index(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $analysis = $this->insightService->getFullAnalysis($telegramUser->id);

    return view('fintech::web.insights.index', compact('analysis'));
  }
}