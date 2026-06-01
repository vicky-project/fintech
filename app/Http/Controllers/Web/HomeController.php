<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\HomeService;
use Modules\FinTech\Models\Notification;
use Nnjeim\World\Models\Currency;
use Modules\FinTech\Traits\ResolvesTelegramUser;

class HomeController extends Controller
{
  use ResolvesTelegramUser;

  protected HomeService $homeService;

  public function __construct(HomeService $homeService) {
    $this->homeService = $homeService;
  }

  public function index(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);

    $data = $this->homeService->getHomeData($telegramUser);
    $monthlyComparison = $this->homeService->getMonthlyComparisonData($telegramUser->id);

    $currency = Currency::where('code', $data['currency'] ?? 'IDR')->first();
    $unreadNotifications = Notification::where('user_id', $telegramUser->id)->unread()->count();

    return view('fintech::web.home', [
      'data' => $data,
      'monthlyComparison' => $monthlyComparison,
      'unreadNotifications' => $unreadNotifications,
      'currencyDetails' => $currency,
    ]);
  }
}