<?php

namespace Modules\FinTech\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class PdfDecryptor
{
  /**
  * Mendekripsi PDF yang diproteksi password menggunakan QPDF.
  *
  * @param string $inputPath  Path file PDF terenkripsi
  * @param string $password   Password dari user
  * @param string $outputPath Path output (opsional)
  * @return string|null       Path file PDF yang sudah didekripsi, atau null jika gagal
  * @throws \Exception
  */
  public function decrypt(string $inputPath, string $password, ?string $outputPath = null): ?string
  {
    if (!file_exists($inputPath)) {
      throw new \Exception("File input tidak ditemukan: {$inputPath}");
    }

    $outputPath = $outputPath ?? $inputPath . '.decrypted.pdf';

    $command = [
      'qpdf',
      '--password=' . escapeshellarg($password),
      '--decrypt',
      escapeshellarg($inputPath),
      escapeshellarg($outputPath)
    ];

    $process = new Process($command);
    $process->setTimeout(60);

    try {
      $process->mustRun();

      // Verifikasi output
      if (file_exists($outputPath) && filesize($outputPath) > 0) {
        Log::info("PDF berhasil didekripsi", ['input' => $inputPath, 'output' => $outputPath]);
        return $outputPath;
      }

      throw new \Exception("File output tidak valid.");
    } catch (ProcessFailedException $e) {
      $error = $process->getErrorOutput();

      if (str_contains($error, 'invalid password')) {
        throw new \Exception("Password yang dimasukkan salah.");
      }

      Log::error("QPDF gagal: " . $error);
      throw new \Exception("Gagal mendekripsi PDF: " . $e->getMessage());
    }
  }

  /**
  * Cek apakah PDF terproteksi password.
  */
  public function isEncrypted(string $filePath): bool
  {
    $parser = new \Smalot\PdfParser\Parser();
    try {
      $parser->parseFile($filePath);
      return false;
    } catch (\Exception $e) {
      return str_contains($e->getMessage(), 'password') ||
      str_contains($e->getMessage(), 'encrypted');
    }
  }
}