<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\NotificationService;

class NotificationController extends Controller
{
  protected NotificationService $notificationService;

  public function __construct(NotificationService $notificationService) {
    $this->notificationService = $notificationService;
  }

  public function index(Request $request): JsonResponse
  {
    $notifications = $this->notificationService->getForUser($request->user()->id);
    $unreadCount = $this->notificationService->getUnreadCount($request->user()->id);

    return response()->json([
      'success' => true,
      'data' => $notifications,
      'unread_count' => $unreadCount,
    ]);
  }

  public function markRead(int $id, Request $request): JsonResponse
  {
    $this->notificationService->markAsRead($id, $request->user()->id);
    return response()->json(['success' => true]);
  }

  public function markAllRead(Request $request): JsonResponse
  {
    $this->notificationService->markAllAsRead($request->user()->id);
    return response()->json(['success' => true]);
  }

  public function unreadCount(Request $request): JsonResponse
  {
    $count = $this->notificationService->getUnreadCount($request->user()->id);
    return response()->json(['success' => true, 'count' => $count]);
  }
}