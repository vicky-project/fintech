<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
  protected $table = 'fintech_exchange_rates';

  protected $fillable = [
    'from_currency',
    'to_currency',
    'rate',
    'fetched_at'
  ];

  protected $casts = [
    'rate' => 'float',
    'fetched_at' => 'datetime',
  ];
}