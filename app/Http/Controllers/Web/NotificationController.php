<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\NotificationService;
use Modules\FinTech\Traits\ResolvesTelegramUser;

class NotificationController extends Controller
{
  use ResolvesTelegramUser;

  protected NotificationService $notificationService;

  public function __construct(NotificationService $notificationService) {
    $this->notificationService = $notificationService;
  }

  public function index(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $notifications = $this->notificationService->getForUser($telegramUser->id);

    return view('fintech:web.notifications.index', compact('notifications'));
  }

  public function markRead(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $this->notificationService->markAsRead($id, $telegramUser->id);

    return back()->with('success', 'Notifikasi ditandai dibaca.');
  }

  public function markAllRead(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $this->notificationService->markAllAsRead($telegramUser->id);

    return back()->with('success', 'Semua notifikasi ditandai dibaca.');
  }
}