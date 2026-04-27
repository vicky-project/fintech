<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\FinTech\Http\Requests\ExportRequest;
use Modules\FinTech\Services\ExportService;
use Modules\Telegram\Services\Support\TelegramApi;

class ExportController extends Controller
{
  protected ExportService $exportService;

  public function __construct(ExportService $exportService) {
    $this->exportService = $exportService;
  }

  public function export(ExportRequest $request): JsonResponse
  {
    $user = $request->user();
    $chatId = $user->telegram_id;

    if (!$chatId) {
      return response()->json([
        'success' => false,
        'message' => 'Akun Anda belum terhubung dengan Telegram. Silakan restart aplikasi.',
      ], 400);
    }

    try {
      // 1. Generate file
      $filePath = $this->exportService->generate($request->validated());

      // 2. Kirim via Telegram
      $telegramApi = app(TelegramApi::class);
      $caption = "✅ Export " . ucfirst($request->type) . " selesai!\n" . now()->format('d M Y H:i');

      $sent = $telegramApi->sendDocument(
        chatId: $chatId,
        filePath: $filePath,
        caption: $caption,
      );

      // 3. Hapus file sementara
      if (file_exists($filePath)) {
        unlink($filePath);
      }

      if ($sent) {
        return response()->json([
          'success' => true,
          'message' => 'File berhasil dikirim ke Telegram Anda. 📁',
        ]);
      } else {
        // Mungkin bot belum di-start user
        return response()->json([
          'success' => false,
          'message' => 'Silakan mulai bot terlebih dahulu dengan klik /start di @NamaBot.',
        ], 400);
      }

      // Fallback: download langsung
      return $this->downloadFallback($filePath, $request->type, $request->format);
    } catch (\Exception $e) {
      Log::error('Export error', ['error' => $e->getMessage()]);

      return response()->json([
        'success' => false,
        'message' => 'Gagal mengekspor: ' . $e->getMessage(),
      ], 500);
    }
  }

  protected function downloadFallback(string $filePath, string $type, string $format) {
    if (!file_exists($filePath)) {
      return response()->json(['success' => false, 'message' => 'File tidak ditemukan.'], 500);
    }

    $filename = "export_{$type}_" . now()->format('YmdHis') . ".{$format}";
    return response()->download($filePath, $filename)->deleteFileAfterSend(true);
  }
}