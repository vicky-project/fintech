<?php

namespace Modules\FinTech\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ExcelDecryptor
{
  /**
  * Cek apakah file Excel terenkripsi (bukan hanya proteksi sheet).
  */
  public function isEncrypted(string $filePath): bool
  {
    try {
      $reader = IOFactory::createReaderForFile($filePath);
      $reader->setReadDataOnly(true);
      $reader->load($filePath);
      return false;
    } catch (ReaderException $e) {
      $message = strtolower($e->getMessage());
      return str_contains($message, 'password') ||
      str_contains($message, 'encrypted') ||
      str_contains($message, 'protected');
    } catch (\Exception $e) {
      $message = strtolower($e->getMessage());
      // Jika gagal karena ZIP, anggap terenkripsi
      if (str_contains($message, 'zip') || str_contains($message, '_rels')) {
        return true;
      }
      throw $e;
    }
  }

  /**
  * Dekripsi file Excel dengan password.
  */
  public function decrypt(string $inputPath, string $password): string
  {
    if (!file_exists($inputPath)) {
      throw new \Exception("File input tidak ditemukan: {$inputPath}");
    }

    $extension = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
    $outputPath = dirname($inputPath) . '/' . uniqid('decrypted_') . '.' . $extension;

    // 1. Coba dengan msoffcrypto-tool jika tersedia (lebih robust)
    if ($this->isMsoffcryptoAvailable()) {
      try {
        return $this->decryptWithMsoffcrypto($inputPath, $password, $outputPath);
      } catch (\Exception $e) {
        Log::warning("msoffcrypto-tool failed: " . $e->getMessage());
      }
    }

    // 2. Coba dengan PhpSpreadsheet
    try {
      return $this->decryptWithPhpSpreadsheet($inputPath, $password, $outputPath);
    } catch (\Exception $e) {
      // Jika gagal karena ZIP, beri tahu user untuk simpan ulang
      if (str_contains($e->getMessage(), 'zip') || str_contains($e->getMessage(), '_rels')) {
        throw new \Exception(
          "File Excel ini menggunakan enkripsi yang tidak didukung. " .
          "Silakan buka file dengan Microsoft Excel, simpan ulang tanpa password (Save As -> Tools -> General Options -> hapus password), lalu upload kembali."
        );
      }
      throw $e;
    }
  }

  protected function isMsoffcryptoAvailable(): bool
  {
    $process = new Process(['msoffcrypto-tool', '--version']);
    $process->run();
    return $process->isSuccessful();
  }

  protected function decryptWithMsoffcrypto(string $inputPath, string $password, string $outputPath): string
  {
    $command = [
      'msoffcrypto-tool',
      $inputPath,
      $outputPath,
      '--password=' . $password
    ];

    $process = new Process($command);
    $process->setTimeout(120);

    try {
      $process->mustRun();

      if (file_exists($outputPath) && filesize($outputPath) > 0) {
        Log::info("Excel didekripsi dengan msoffcrypto-tool", ['output' => $outputPath]);
        return $outputPath;
      }

      throw new \Exception("File output tidak valid.");
    } catch (ProcessFailedException $e) {
      $error = $process->getErrorOutput();
      Log::error("msoffcrypto-tool error: " . $error);

      if (str_contains(strtolower($error), 'password')) {
        throw new \Exception("Password Excel yang dimasukkan salah.");
      }

      throw new \Exception("Gagal mendekripsi dengan msoffcrypto-tool.");
    }
  }

  protected function decryptWithPhpSpreadsheet(string $inputPath, string $password, string $outputPath): string
  {
    $reader = IOFactory::createReaderForFile($inputPath);
    $reader->setReadDataOnly(true);

    if (method_exists($reader, 'setPassword')) {
      $reader->setPassword($password);
    }

    $spreadsheet = $reader->load($inputPath);

    $writer = IOFactory::createWriter($spreadsheet, ucfirst(pathinfo($inputPath, PATHINFO_EXTENSION)));
    $writer->save($outputPath);

    if (file_exists($outputPath) && filesize($outputPath) > 0) {
      Log::info("Excel didekripsi dengan PhpSpreadsheet", ['output' => $outputPath]);
      return $outputPath;
    }

    throw new \Exception("File output tidak valid.");
  }
}