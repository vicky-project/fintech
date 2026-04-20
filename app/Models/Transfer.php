<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FinTech\Casts\MoneyCastWithoutCurrency;

class Transfer extends Model
{
  use SoftDeletes;

  protected $table = 'fintech_transfers';

  protected $fillable = [
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

  public function getFormattedAmount(): string
  {
    return $this->amount->formatTo('id_ID');
  }
}