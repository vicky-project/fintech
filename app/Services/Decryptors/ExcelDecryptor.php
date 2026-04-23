<?php

namespace Modules\FinTech\Services\Decryptors;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ExcelDecryptor
{
  public function __construct() {
    // Pastikan path ini sesuai dengan tempat Anda menyimpan file yang diunduh
    $libPath = base_path(__DIR__ .'/PHPDecryptXLSXWithPassword.php');

    if (file_exists($libPath)) {
      require_once $libPath;
    }
  }

  public function isEncrypted(string $filePath): bool
  {
    try {
      $fileType = $this->determineType($filePath);
      if (!$fileType) {
        throw new \Exception("Unknown file format. Only .xls and xlsx allowed");
      }
      $reader = IOFactory::createReader($fileType);
      $reader->setReadDataOnly(true);
      $reader->load($filePath);
      return false;
    } catch (ReaderException $e) {
      Log::debug("Reader Exception: " . $e->getMessage(), ["path" => $filePath]);
      $message = strtolower($e->getMessage());
      return str_contains($message, 'password') ||
      str_contains($message, 'encrypted') ||
      str_contains($message, 'protected');
    } catch (\Exception $e) {
      $message = strtolower($e->getMessage());
      Log::debug("Exception: ". $message);
      // Error ZIP/_rels biasanya indikasi kuat file terenkripsi Agile
      if (str_contains($message, 'zip') || str_contains($message, '_rels')) {
        return true;
      }
      throw $e;
    }
  }

  public function decrypt(string $inputPath, string $password): string
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

    // 2. Coba dengan PHPDecryptXLSXWithPassword (Metode Utama untuk ZIP Error)
    // Kita prioritaskan ini sebelum PhpSpreadsheet karena PhpSpreadsheet sering gagal pada ZIP/Agile Encryption
    try {
      return $this->decryptWithCustomLibrary($inputPath, $password, $outputPath);
    } catch (\Exception $e) {
      Log::warning("PHPDecryptXLSXWithPassword gagal, mencoba PhpSpreadsheet sebagai fallback: " . $e->getMessage());
    }

    // 3. Coba dengan PhpSpreadsheet (Fallback terakhir)
    try {
      return $this->decryptWithPhpSpreadsheet($inputPath, $password, $outputPath);
    } catch (\Exception $e) {
      Log::error("Semua metode dekripsi gagal.", ['message' => $e->getMessage()]);

      throw new \Exception(
        "File Excel ini menggunakan enkripsi yang tidak dapat didekripsi secara otomatis. " .
        "Silakan buka file dengan Microsoft Excel, simpan ulang tanpa password, lalu upload kembali. " .
        "Error: " . $e->getMessage()
      );
    }
  }

  /**
  * Dekripsi menggunakan PHPDecryptXLSXWithPassword.
  */
  protected function decryptWithCustomLibrary(string $inputPath, string $password, string $outputPath): string
  {
    // Cek apakah fungsi decrypt dari library sudah terload (fungsi global)
    if (!function_exists('excel_decrypt')) {
      throw new \Exception("Fungsi dekripsi library kustom tidak ditemukan. Periksa pemuatan file PHPDecryptXLSXWithPassword.php.");
    }

    try {
      // Memanggil fungsi global decrypt(input, password, output) dari library yang diunduh
      excel_decrypt($inputPath, $password, $outputPath);

      if (file_exists($outputPath) && filesize($outputPath) > 0) {
        Log::info("Excel berhasil didekripsi dengan PHPDecryptXLSXWithPassword", ['output' => $outputPath]);
        return $outputPath;
      }

      throw new \Exception("File output tidak dihasilkan oleh library kustom.");
    } catch (\Exception $e) {
      Log::error("PHPDecryptXLSXWithPassword gagal: " . $e->getMessage());
      throw $e;
    }
  }

  protected function isMsoffcryptoAvailable(): bool
  {
    // Suppress error jika command tidak ditemukan
    try {
      $process = new Process(['msoffcrypto-tool', '--version']);
      $process->run();
      return $process->isSuccessful();
    } catch (\Exception $e) {
      return false;
    }
  }

  protected function decryptWithMsoffcrypto(string $inputPath, string $password, string $outputPath): string
  {
    $command = ['msoffcrypto-tool',
      $inputPath,
      $outputPath,
      '--password=' . $password];
    $process = new Process($command);
    $process->setTimeout(120);

    try {
      $process->mustRun();
      if (file_exists($outputPath) && filesize($outputPath) > 0) {
        return $outputPath;
      }
      throw new \Exception("File output tidak valid.");
    } catch (ProcessFailedException $e) {
      throw new \Exception("msoffcrypto-tool error: " . $process->getErrorOutput());
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
    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save($outputPath);

    return $outputPath;
  }

  protected function determineType(string $filePath): ?string
  {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    return match($ext) {
      'xls' => 'Xls',
      'xlsx' => 'Xlsx',
      default => null
      };
    }
  }