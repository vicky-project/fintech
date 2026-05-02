<?php
namespace Modules\FinTech\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Modules\Telegram\Models\TelegramUser;
use Modules\FinTech\Services\BackupService;
use Modules\Telegram\Services\Support\TelegramApi;

class CreateBackupJob implements ShouldQueue
{
  use Dispatchable,
  Queueable;

  public function __construct(protected TelegramUser $user) {}

  public function handle(BackupService $backupService, TelegramApi $telegramApi): void
  {
    // 1. Generate backup
    $backupGzip = $backupService->export($this->user);

    // 2. Simpan ke file temp
    $filename = sprintf('backup_%s_%s.json.gz', $this->user->telegram_id, now()->format('YmdHis'));
    $tempPath = storage_path("app/backups/{$filename}");

    if (!is_dir(dirname($tempPath))) {
      mkdir(dirname($tempPath), 0755, true);
    }

    file_put_contents($tempPath, $backupGzip);

    // 3. Kirim via TelegramApi
    $telegramApi->sendDocument(
      chatId: $this->user->telegram_id,
      filePath: $tempPath,
      caption: '✅ Backup data keuangan Anda berhasil dibuat.',
    );

    // 4. Hapus file temp
    if (file_exists($tempPath)) {
      unlink($tempPath);
    }
  }
}