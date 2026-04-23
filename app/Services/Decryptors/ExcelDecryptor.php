<?php

namespace Modules\FinTech\Services\Decryptors;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

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
      Log::debug("Reader Exception: " . $e->getMessage(), ["path" => $filePath]);
      $message = strtolower($e->getMessage());
      return str_contains($message, 'password') || str_contains($message, 'encrypted') || str_contains($message, 'protected') || str_contains($message, 'unable to identify');
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
        $errorMsg = $e->getMessage();
        // Jika error berasal dari password salah, langsung lempar
        if (str_contains($errorMsg, 'password salah') || str_contains($errorMsg, 'incorrect password')) {
          throw $e;
        }
        Log::warning("msoffcrypto-tool gagal: " . $errorMsg);
      }
    }

    // 2. Coba dengan PhpSpreadsheet
    try {
      return $this->decryptWithPhpSpreadsheet($inputPath, $password, $outputPath);
    } catch (\Exception $e) {
      $message = $e->getMessage();
      if (str_contains($message, 'password') || str_contains($message, 'Password')) {
        throw new \Exception("Password Excel yang dimasukkan salah.");
      }
      throw $e;
    }
  }

  protected function isMsoffcryptoAvailable(): bool
  {
    try {
      $process = new Process(['which', 'msoffcrypto-tool']);
      $process->run();
      return $process->isSuccessful();
    } catch (\Exception $e) {
      return false;
    }
  }

  protected function decryptWithMsoffcrypto(string $inputPath, string $password, string $outputPath): string
  {
    $command = [
      'msoffcrypto-tool',
      '-p',
      $password,
      $inputPath,
      $outputPath
    ];

    $process = new Process($command);
    $process->setTimeout(120);

    try {
      $process->mustRun();
      if (file_exists($outputPath) && filesize($outputPath) > 0) {
        return $outputPath;
      }
      throw new \Exception("File output tidak valid atau kosong.");
    } catch (ProcessFailedException $e) {
      $errorOutput = $process->getErrorOutput();

      Log::error("msoffcrypto-tool gagal", [
        'command' => $process->getCommandLine(),
        'exit_code' => $process->getExitCode(),
        'error' => $errorOutput,
        'output' => $process->getOutput()
      ]);

      $passwordWrongPatterns = [
        '/The file could not be decrypted with this password/i',
        '/The password is incorrect/i',
        '/File is password-free/i',
        '/Incorrect password/i',
        '/Wrong password/i'
      ];

      foreach ($passwordWrongPatterns as $pattern) {
        if (preg_match($pattern, $errorOutput)) {
          throw new \Exception("Password Excel yang dimasukkan salah.");
        }
      }

      if (str_contains($errorOutput, 'not a valid OLE file') ||
        str_contains($errorOutput, 'not a valid Office Open XML file')) {
        throw new \Exception("File tidak valid atau bukan file Excel yang didukung.");
      }

      if (str_contains($errorOutput, 'unsupported encryption')) {
        throw new \Exception("Enkripsi file ini tidak didukung. Silakan buka manual dengan Excel dan simpan ulang tanpa password.");
      }

      throw new \Exception("Gagal mendekripsi file Excel. Silakan coba buka manual dengan Excel dan simpan ulang tanpa password.");
    }
  }

  protected function decryptWithPhpSpreadsheet(string $inputPath, string $password, string $outputPath): string
  {
    try {
      $reader = IOFactory::createReaderForFile($inputPath);
      $reader->setReadDataOnly(true);

      if (method_exists($reader, 'setPassword')) {
        $reader->setPassword($password);
      }

      $spreadsheet = $reader->load($inputPath);
      $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
      $writer->save($outputPath);

      return $outputPath;
    } catch (ReaderException $e) {
      Log::error("PhpSpreadsheet decryption failed: " . $e->getMessage());
      if (str_contains($e->getMessage(), 'password') || str_contains($e->getMessage(), 'Password')) {
        throw new \Exception("Password Excel yang dimasukkan salah.");
      }
      throw new \Exception("Gagal mendekripsi file Excel: " . $e->getMessage());
    }
  }
}