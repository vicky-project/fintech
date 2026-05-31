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
  ],
  'apised' => [
    'api_key' => env('APISED_API_KEY'),
    'base_url' => env('APISED_BASE_URL', 'https://gold.g.apised.com'),
  ],
  'world' => [
    'migrations' => [
      'countries' => [
        'table_name' => 'countries',
        'optional_fields' => [
          'phone_code' => [
            'required' => true,
            'type' => 'string',
            'length' => 5,
          ],
          'iso3' => [
            'required' => true,
            'type' => 'string',
            'length' => 3,
          ],
          'native' => [
            'required' => false,
            'type' => 'string',
          ],
          'region' => [
            'required' => true,
            'type' => 'string',
          ],
          'subregion' => [
            'required' => true,
            'type' => 'string',
          ],
          'latitude' => [
            'required' => false,
            'type' => 'string',
          ],
          'longitude' => [
            'required' => false,
            'type' => 'string',
          ],
          'emoji' => [
            'required' => false,
            'type' => 'string',
          ],
          'emojiU' => [
            'required' => false,
            'type' => 'string',
          ],
        ],
      ],
      'states' => [
        'table_name' => 'states',
        'optional_fields' => [
          'country_code' => [
            'required' => true,
            'type' => 'string',
            'length' => 3,
          ],
          'state_code' => [
            'required' => false,
            'type' => 'string',
            'length' => 5,
          ],
          'type' => [
            'required' => false,
            'type' => 'string',
          ],
          'latitude' => [
            'required' => false,
            'type' => 'string',
          ],
          'longitude' => [
            'required' => false,
            'type' => 'string',
          ],
        ],
      ],
      'cities' => [
        'table_name' => 'cities',
        'optional_fields' => [
          'country_code' => [
            'required' => true,
            'type' => 'string',
            'length' => 3,
          ],
          'state_code' => [
            'required' => false,
            'type' => 'string',
            'length' => 5,
          ],
          'latitude' => [
            'required' => false,
            'type' => 'string',
          ],
          'longitude' => [
            'required' => false,
            'type' => 'string',
          ],
        ],
      ],
      'timezones' => [
        'table_name' => 'timezones',
      ],
      'currencies' => [
        'table_name' => 'currencies',
      ],
      'languages' => [
        'table_name' => 'languages',
      ],
    ],

  ]
];