<?php

namespace Modules\FinTech\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use Illuminate\Support\Facades\Log;

class ExcelDecryptor
{
  /**
  * Cek apakah file Excel terproteksi password.
  */
  public function isEncrypted(string $filePath): bool
  {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    try {
      $reader = $this->createReaderByExtension($extension);
      if (!$reader) {
        $reader = IOFactory::createReaderForFile($filePath);
      }
      $reader->setReadDataOnly(true);
      // Coba buka tanpa password – jika gagal dengan pesan terkait password, berarti terproteksi
      $reader->load($filePath);
      return false;
    } catch (ReaderException $e) {
      $message = strtolower($e->getMessage());
      Log::error("Failed to open file.", [
        'message' => $message,
        'trace' => $e->getTraceAsString()
      ]);

      return str_contains($message, 'password') || str_contains($message, 'encrypted') || str_contains($message, 'protected') || str_contains($message, 'zip');
    } catch(\Exception $e) {
      Log::warning("Excel isEncrypted fallback: ". $e->getMessage());
      return true;
    }
  }

  /**
  * Dekripsi file Excel dengan password.
  * Mengembalikan path file baru yang sudah didekripsi (tanpa password).
  *
  * @param string $inputPath
  * @param string $password
  * @return string Path file hasil dekripsi
  * @throws \Exception
  */
  public function decrypt(string $inputPath, string $password): string
  {
    if (!file_exists($inputPath)) {
      throw new \Exception("File input tidak ditemukan: {$inputPath}");
    }

    $extension = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
    $outputPath = dirname($inputPath) . '/' . uniqid('decrypted_') . '.' . $extension;

    try {
      $reader = $this->createReaderByExtension($extension);
      if (!$reader) {
        $reader = IOFactory::createReaderForFile($inputPath);
      }
      $reader->setReadDataOnly(true);

      // Set password untuk membuka file (PhpSpreadsheet)
      if (method_exists($reader, 'setPassword')) {
        $reader->setPassword($password);
      }

      $spreadsheet = $reader->load($inputPath);

      // Tentukan writer berdasarkan ekstensi
      $writerType = ucfirst($extension);
      $writer = IOFactory::createWriter($spreadsheet, $writerType);
      $writer->save($outputPath);

      if (file_exists($outputPath) && filesize($outputPath) > 0) {
        Log::info("Excel berhasil didekripsi", ['input' => $inputPath, 'output' => $outputPath]);
        return $outputPath;
      }

      throw new \Exception("File output tidak valid atau kosong.");
    } catch (ReaderException $e) {
      $error = $e->getMessage();
      Log::error("Excel decryption failed", ['error' => $error, 'file' => $inputPath, 'trace' => $e->getTraceAsString()]);

      if (str_contains(strtolower($error), 'password')) {
        throw new \Exception("Password Excel yang dimasukkan salah.");
      }

      throw new \Exception("Gagal mendekripsi file Excel: " . $e->getMessage());
    } catch (\Exception $e) {
      Log::error("Excel processing error", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
      throw new \Exception("Gagal memproses file Excel: " . $e->getMessage());
    }
  }

  protected function createReaderByExtension(string $extension): ?object
  {
    return match($extension) {
      'xlsx' => new Xlsx(),
      'xls' => new Xls(),
      default => null,
      };
    }
  }