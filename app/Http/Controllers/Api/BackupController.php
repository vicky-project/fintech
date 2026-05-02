<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\BackupService;
use Modules\Telegram\Models\TelegramUser;
use Modules\Telegram\Services\Support\TelegramApi;
use Modules\FinTech\Jobs\CreateBackupJob; // jika pakai queue

class BackupController extends Controller
{
  protected BackupService $backupService;
  protected TelegramApi $telegramApi;

  public function __construct(BackupService $backupService, TelegramApi $telegramApi) {
    $this->backupService = $backupService;
    $this->telegramApi = $telegramApi;
  }

  /**
  * Membuat backup dan mengirimkannya langsung ke Telegram.
  */
  public function send(Request $request) {
    /** @var TelegramUser $user */
    $user = $request->user();

    if (!$user instanceof TelegramUser) {
      abort(401, 'Unauthorized');
    }

    // Jika data besar, gunakan queue agar respons cepat
    if ($this->shouldUseQueue($user)) {
      CreateBackupJob::dispatch($user);
      return response()->json([
        'status' => 'success',
        'message' => 'Permintaan backup diterima. File akan dikirim ke chat Anda.',
      ]);
    }

    return $this->processBackup($user);
  }

  /**
  * Proses backup sebenarnya: generate file, simpan, kirim, hapus.
  */
  protected function processBackup(TelegramUser $user): \Illuminate\Http\JsonResponse
  {
    try {
      // 1. Hasilkan konten backup (gzip)
      $backupGzip = $this->backupService->export($user);

      // 2. Simpan ke file sementara
      $filename = sprintf('backup_%s_%s.json.gz', $user->telegram_id, now()->format('YmdHis'));
      $tempPath = storage_path("app/backups/{$filename}");

      if (!is_dir(dirname($tempPath))) {
        mkdir(dirname($tempPath), 0755, true);
      }

      file_put_contents($tempPath, $backupGzip);

      // 3. Kirim via TelegramApi
      $sent = $this->telegramApi->sendDocument(
        chatId: $user->telegram_id,
        filePath: $tempPath,
        caption: '✅ Backup data keuangan Anda berhasil dibuat.',
      );

      // 4. Hapus file lokal (selesai dikirim)
      if (file_exists($tempPath)) {
        unlink($tempPath);
      }

      if ($sent) {
        return response()->json([
          'status' => 'success',
          'message' => 'Backup telah dikirim ke Telegram Anda.',
        ]);
      } else {
        return response()->json([
          'status' => 'error',
          'message' => 'Gagal mengirim file backup ke Telegram.',
        ], 500);
      }
    } catch (\Exception $e) {
      // Optional: hapus file jika masih ada
      if (isset($tempPath) && file_exists($tempPath)) {
        unlink($tempPath);
      }
      return response()->json([
        'status' => 'error',
        'message' => 'Backup gagal: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
  * Tentukan apakah perlu menggunakan queue berdasarkan perkiraan beban.
  */
  protected function shouldUseQueue(TelegramUser $user): bool
  {
    // Contoh: jika transaksi > 50.000, gunakan queue
    $count = \Modules\FinTech\Models\Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))->count();
    return $count > 50000;
  }
}