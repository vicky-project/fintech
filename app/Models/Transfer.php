<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FinTech\Casts\MoneyCastWithoutCurrency;
use Modules\FinTech\Traits\HasCurrencyFormatting;
use Modules\FinTech\Traits\HasUuid;

class Transfer extends Model
{
  use SoftDeletes,
  HasCurrencyFormatting,
  HasUuid;

  protected $table = 'fintech_transfers';

  protected $fillable = [
    'uuid',
    'from_wallet_id',
    'to_wallet_id',
    'amount',
    'transfer_date',
    'description'
  ];

  protected $casts = [
    'amount' => MoneyCastWithoutCurrency::class,
    'transfer_date' => 'date',
  ];

  public function fromWallet(): BelongsTo
  {
    return $this->belongsTo(Wallet::class, 'from_wallet_id');
  }

  public function toWallet(): BelongsTo
  {
    return $this->belongsTo(Wallet::class, 'to_wallet_id');
  }

  public function getAmountFloat(): float
  {
    return $this->amount->getAmount()->toFloat();
  }

  protected function getCurrencyRules(): array
  {
    $default = $this->defaultCurrencyFormat();

    if (method_exists($this, 'fromWallet') && $this->fromWallet && $this->fromWallet->currencyDetails) {
      $currency = $this->fromWallet->currencyDetails;
      return [
        'precision' => $currency->precision ?? $default['precision'],
        'decimal_mark' => $currency->decimal_mark ?? $default['decimal_mark'],
        'thousands_separator' => $currency->thousands_separator ?? $default['thousands_separator'],
        'symbol' => $currency->symbol ?? $default['symbol'],
        'symbol_first' => $currency->symbol_first ?? $default['symbol_first'],
      ];
    }

    return $default;
  }
}