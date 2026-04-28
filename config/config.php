<?php

return [
  'name' => 'FinTech',
  'default_currency' => 'IDR',
  'telegram' => [
    'admin_id' => env('TELEGRAM_CHAT_ID')
  ],
  'google' => [
    'oauth_client_id' => env('GOOGLE_CLIENT_ID'),
    'oauth_client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'oauth_redirect_uri' => env('GOOGLE_REDIRECT')
  ]
];