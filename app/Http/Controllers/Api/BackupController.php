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

    $password = $request->input('password');
    if ($password !== null) {
      $request->validate([
        'password' => 'string|min:4|max:100'
      ]);
    }

    // Jika data besar, gunakan queue agar respons cepat
    if ($this->shouldUseQueue($user)) {
      CreateBackupJob::dispatch($user, $password);
      return response()->json([
        'success' => true,
        'message' => 'Permintaan backup diterima. File akan dikirim ke chat Anda.',
      ]);
    }

    return $this->processBackup($user, $password);
  }

  /**
  * Proses backup sebenarnya: generate file, simpan, kirim, hapus.
  */
  protected function processBackup(TelegramUser $user, ?string $password = null): \Illuminate\Http\JsonResponse
  {
    try {
      // 1. Hasilkan konten backup (gzip, bisa terenkripsi jika ada password)
      $backupGzip = $this->backupService->export($user, $password);

      // 2. Simpan ke file sementara
      $filename = sprintf('backup_%s_%s.json.gz', $user->telegram_id, now()->format('YmdHis'));
      $tempPath = storage_path("app/backups/{$filename}");

      if (!is_dir(dirname($tempPath))) {
        mkdir(dirname($tempPath), 0755, true);
      }

      file_put_contents($tempPath, $backupGzip);

      // 3. Kirim via TelegramApi
      $caption = '✅ Backup data keuangan Anda berhasil dibuat.';
      if ($password) {
        $caption .= "\n\n🔒 File ini dienkripsi dengan password. Simpan password Anda dengan aman.";
      }

      $sent = $this->telegramApi->sendDocument(
        chatId: $user->telegram_id,
        filePath: $tempPath,
        caption: $caption,
      );

      // 4. Hapus file lokal
      if (file_exists($tempPath)) {
        unlink($tempPath);
      }

      if ($sent) {
        return response()->json([
          'success' => true,
          'message' => 'Backup telah dikirim ke Telegram Anda.',
        ]);
      } else {
        return response()->json([
          'success' => false,
          'message' => 'Gagal mengirim file backup ke Telegram.',
        ], 500);
      }
    } catch (\Exception $e) {
      if (isset($tempPath) && file_exists($tempPath)) {
        unlink($tempPath);
      }
      return response()->json([
        'success' => false,
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

  /**
  * Upload file backup dan pulihkan data user.
  */
  public function upload(Request $request) {
    /** @var TelegramUser $user */
    $user = $request->user();
    if (!$user instanceof TelegramUser) {
      abort(401, 'Unauthorized');
    }

    // Validasi file
    $request->validate([
      'backup_file' => [
        'required',
        'file',
        'max:20480', // max 20 MB
        function ($attribute, $value, $fail) {
          $mime = $value->getMimeType();
          $ext = $value->getClientOriginalExtension();
          if (!in_array($mime, ['application/gzip', 'application/json', 'application/octet-stream']) &&
            !in_array($ext, ['gz', 'json'])) {
            $fail('Format file tidak didukung. Gunakan .json atau .json.gz');
          }
        }],
      'password' => 'nullable|string|min:4|max:100'
    ],
      [
        'backup_file.required' => 'File backup wajib diunggah.',
        'backup_file.max' => 'Ukuran file maksimal 20 MB.',
      ]);

    $file = $request->file('backup_file');
    $content = file_get_contents($file->getRealPath());
    $password = $request->input('password');

    try {
      $this->backupService->import(
        $user,
        $content,
        $password
      );
      return response()->json([
        'success' => true,
        'message' => '✅ Data berhasil dipulihkan. Semua data keuangan Anda telah dikembalikan.',
      ]);
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => '❌ Gagal memulihkan data: ' . $e->getMessage(),
      ],
        422);
    }
  }
}