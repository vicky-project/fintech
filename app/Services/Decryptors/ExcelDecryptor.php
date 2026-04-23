<?php

namespace Modules\FinTech\Services\Decryptors;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

// Load library manual untuk enkripsi AES-256 yang digunakan Office 2016+
require_once __DIR__ . '/PHPDecryptXLSXWithPassword.php';

class ExcelDecryptor
{
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
      if (str_contains($message, 'zip') || str_contains($message, '_rels')) {
        return true;
      }
      throw $e;
    }
  }

  public function decrypts(string $inputPath, string $password): string
  {
    if (!file_exists($inputPath)) {
      throw new \Exception("File input tidak ditemukan: {$inputPath}");
    }

    $extension = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
    $outputPath = dirname($inputPath) . '/' . uniqid('decrypted_') . '.' . $extension;

    // 1. Coba dengan msoffcrypto-tool jika tersedia
    if ($this->isMsoffcryptoAvailable()) {
      try {
        return $this->decryptWithMsoffcrypto($inputPath, $password, $outputPath);
      } catch (\Exception $e) {
        Log::warning("msoffcrypto-tool gagal: " . $e->getMessage());
      }
    }

    // 2. Coba dengan PhpSpreadsheet
    try {
      return $this->decryptWithPhpSpreadsheet($inputPath, $password, $outputPath);
    } catch (\Exception $e) {
      if (str_contains($e->getMessage(), 'zip') || str_contains($e->getMessage(), '_rels')) {
        Log::info("PhpSpreadsheet gagal karena enkripsi ZIP, mencoba PHPDecryptXLSXWithPassword...");
        // Jangan lempar error dulu, coba metode terakhir
      } else {
        throw $e;
      }
    }

    // 3. Coba dengan PHPDecryptXLSXWithPassword (metode terakhir)
    try {
      return $this->decryptWithCustomLibrary($inputPath, $password, $outputPath);
    } catch (\Exception $e) {
      throw new \Exception(
        "File Excel ini menggunakan enkripsi yang tidak dapat didekripsi secara otomatis. " .
        "Silakan buka file dengan Microsoft Excel, simpan ulang tanpa password, lalu upload kembali. " .
        "Error: " . $e->getMessage()
      );
    }
  }

  /**
  * Dekripsi menggunakan PHPDecryptXLSXWithPassword.
  * Library ini mengimplementasikan dekripsi AES-256 murni dengan PHP.
  */
  protected function decryptWithCustomLibrary(string $inputPath, string $password, string $outputPath): string
  {
    try {
      // Panggil fungsi global `decrypt` yang disediakan oleh library
      \decrypt($inputPath, $password, $outputPath);

      if (file_exists($outputPath) && filesize($outputPath) > 0) {
        Log::info("Excel berhasil didekripsi dengan PHPDecryptXLSXWithPassword", ['output' => $outputPath]);
        return $outputPath;
      }

      throw new \Exception("File output tidak valid.");
    } catch (\Exception $e) {
      Log::error("PHPDecryptXLSXWithPassword gagal: " . $e->getMessage());

      // Periksa apakah error terkait password
      if (str_contains(strtolower($e->getMessage()), 'password') ||
        str_contains(strtolower($e->getMessage()), 'decrypt')) {
        throw new \Exception("Password Excel yang dimasukkan salah atau file rusak.");
      }

      throw new \Exception("Gagal mendekripsi dengan library kustom: " . $e->getMessage());
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