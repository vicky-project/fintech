<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\HomeService;
use Modules\FinTech\Models\Wallet;
use Nnjeim\World\Models\Currency;

class HomeController extends Controller
{
  protected HomeService $homeService;

  public function __construct(HomeService $homeService) {
    $this->homeService = $homeService;
  }

  public function index(Request $request) {
    $telegramId = $request->telegram_id;
    $userId = auth()->id();

    $data = $this->homeService->getHomeData(auth()->user());
    $monthlyComparison = $this->homeService->getMonthlyComparisonData($userId);

    $currency = Currency::where('code', $data['currency'] ?? 'IDR')->first();

    $unreadNotifications = \Modules\FinTech\Models\Notification::where('user_id', $userId)->unread()->count();

    return view('fintech.home', [
      'data' => $data,
      'monthlyComparison' => $monthlyComparison,
      'unreadNotifications' => $unreadNotifications,
    ]);
  }
}