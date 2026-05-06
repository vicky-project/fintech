<?php

namespace Modules\FinTech\Services\Google;

use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Drive;

class GoogleClientFactory
{
  /**
  * Buat GoogleClient dengan konfigurasi standar.
  */
  public function create(): GoogleClient
  {
    $client = new GoogleClient();
    $client->setClientId(config('fintech.google.oauth_client_id'));
    $client->setClientSecret(config('fintech.google.oauth_client_secret'));
    $client->setRedirectUri(config('fintech.google.oauth_redirect_uri'));
    $client->addScope([Sheets::SPREADSHEETS, Drive::DRIVE_FILE]);
    $client->setAccessType('offline');

    return $client;
  }
}