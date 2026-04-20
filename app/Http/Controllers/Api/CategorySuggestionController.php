<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Telegram\Services\Support\TelegramApi;

class CategorySuggestionController extends Controller
{
  public function store(Request $request, TelegramApi $telegram): JsonResponse
  {
    $validated = $request->validate([
      'name' => 'required|string|max:100',
      'type' => 'required|in:income,expense',
      'notes' => 'nullable|string'
    ]);

    $chatId = config('fintech.telegram.admin_id', null);
    if (!$chatId) {
      return response()->json([
        'success' => false,
        'message' => 'No admin provided.'
      ]);
    }

    $user = $request->user()->first_name;

    $message = "Rekomendasi kategori baru diterima!\n\nUser: {$user}\nCategory Name: {$validated['name']}\nType: {$validated['type']}\nNotes: {$validated['notes']}";

    $telegram->sendMessage($chatId, $message);

    return response()->json([
      'success' => true,
      'message' => 'Terima kasih! Usulan kategori Anda telah dikirim ke admin.'
    ]);
  }
}