<?php

return [

  'id' => 'fintech',
  'name' => 'FinTech',
  'description' => 'Financial Technology is an assistance of your personal digital finance',
  'icon_emoji' => '💰',
  'render_type' => 'iframe',
  'render_config' => [
    'url' => env("APP_URL") . '/apps/fintech'
  ]
];