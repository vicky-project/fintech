<?php

namespace Modules\FinTech\Services\Decryptors;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
      '--password=' . $password,
      '--decrypt',
      $inputPath,
      $outputPath
    ];

    $process = new Process($command);
    $process->setTimeout(120);

    try {
      $process->mustRun();

      // Verifikasi output
      if (file_exists($outputPath) && filesize($outputPath) > 0) {
        Log::info("PDF berhasil didekripsi", ['input' => $inputPath, 'output' => $outputPath]);
        Storage::delete($inputPath);
        return $outputPath;
      }

      throw new \Exception("File output tidak valid atau kosong.");
    } catch (ProcessFailedException $e) {
      $error = $process->getErrorOutput();
      $output = $process->getOutput();
      Log::error("QPDF gagal. Error: ", [
        "command" => $process->getCommandLine(),
        "error" => $error,
        "output" => $output,
        "exit_code" => $process->getExitCode()
      ]);

      if (str_contains($error, 'invalid password')) {
        throw new \Exception("Password yang dimasukkan salah.");
      }

      if (str_contains($error, "No such file")) {
        throw new \Exception("File tidak ditemukan atau tidak dapat diakses.");
      }

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
      $result = $parser->parseFile($filePath);
      return false;
    } catch (\Exception $e) {
      $message = strtolower($e->getMessage());
      Log::error("Error to parse pdf file. Error: " . $message);

      return str_contains($message,
        'password') ||
      str_contains($message,
        'encrypted') ||
      str_contains($message,
        'secured') ||
      str_contains($message, 'file is encrypted');
    }
  }
}