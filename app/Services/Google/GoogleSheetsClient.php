<?php

namespace Modules\FinTech\Services\Google;

use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Exception as GoogleException;
use Modules\FinTech\Models\UserSetting;
use Illuminate\Support\Facades\Log;

class GoogleSheetsClient
{
  protected GoogleClient $client;
  protected GoogleSheets $sheetsService;
  protected GoogleDrive $driveService;

  public function __construct(GoogleClientFactory $clientFactory) {
    $this->client = $clientFactory->create();
    $this->sheetsService = new GoogleSheets($this->client);
    $this->driveService = new GoogleDrive($this->client);
  }

  /**
  * Setup client untuk user tertentu dengan token OAuth miliknya.
  */
  public function setupForUser($user): void
  {
    $setting = UserSetting::where('user_id', $user->id)->first();
    if (!$setting || !$setting->google_access_token) {
      throw new \Exception('Pengguna belum terhubung dengan Google.');
    }

    $token = [
      'access_token' => $setting->google_access_token,
      'refresh_token' => $setting->google_refresh_token,
      'expires_in' => 3599,
    ];
    $this->client->setAccessToken($token);

    // Refresh jika expired
    if ($this->client->isAccessTokenExpired()) {
      if ($this->client->getRefreshToken()) {
        $newToken = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
        if (!isset($newToken['error'])) {
          // Simpan token baru langsung ke database
          $setting->google_access_token = $newToken['access_token'];
          if (isset($newToken['refresh_token'])) {
            $setting->google_refresh_token = $newToken['refresh_token'];
          }
          if (isset($newToken['expires_in'])) {
            $setting->google_token_expires_at = now()->addSeconds($newToken['expires_in']);
          }
          $setting->save();

          $this->client->setAccessToken($newToken);
        }
      }
    }
  }

  public function getSheetsService(): GoogleSheets
  {
    return $this->sheetsService;
  }

  public function getDriveService(): GoogleDrive
  {
    return $this->driveService;
  }

  public function getClient(): GoogleClient
  {
    return $this->client;
  }

  /**
  * Jalankan callable dengan exponential backoff untuk menangani rate limit (429).
  */
  public function executeWithBackoff(callable $callable, int $maxRetries = 5) {
    $retries = 0;
    $maxBackoff = 64; // detik

    while (true) {
      try {
        return $callable();
      } catch (GoogleException $e) {
        $statusCode = $e->getCode();
        if (($statusCode === 429 || $statusCode === 500 || $statusCode === 503) && $retries < $maxRetries) {
          $wait = min(pow(2, $retries) + mt_rand(0, 1000) / 1000, $maxBackoff);
          Log::warning("Google API rate limited, retrying in {$wait}s (attempt {$retries})", [
            'error' => $e->getMessage(),
          ]);
          sleep((int) ceil($wait));
          $retries++;
        } else {
          throw $e;
        }
      }
    }
  }
}