<?php

namespace Modules\FinTech\Services\Google;

use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;
use Google\Service\Drive as GoogleDrive;
use Modules\FinTech\Models\UserSetting;

class GoogleSheetsClient
{
  protected GoogleClient $client;
  protected GoogleSheets $sheetsService;
  protected GoogleDrive $driveService;

  public function __construct() {
    $this->client = new GoogleClient();
    $this->client->setClientId(config('fintech.google.oauth_client_id'));
    $this->client->setClientSecret(config('fintech.google.oauth_client_secret'));
    $this->client->setRedirectUri(config('fintech.google.oauth_redirect_uri'));
    $this->client->addScope([GoogleSheets::SPREADSHEETS, GoogleDrive::DRIVE_FILE]);
    $this->client->setAccessType('offline');
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
          $this->saveToken($setting, $newToken);
          $this->client->setAccessToken($newToken);
        }
      }
    }

    $this->sheetsService = new GoogleSheets($this->client);
    $this->driveService = new GoogleDrive($this->client);
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

  protected function saveToken(UserSetting $setting, array $token): void
  {
    $setting->google_access_token = $token['access_token'];
    if (isset($token['refresh_token'])) {
      $setting->google_refresh_token = $token['refresh_token'];
    }
    if (isset($token['expires_in'])) {
      $setting->google_token_expires_at = now()->addSeconds($token['expires_in']);
    }
    $setting->save();
  }
}