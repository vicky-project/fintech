<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
  protected $table = 'fintech_user_settings';

  protected $fillable = [
    'user_id',
    'default_currency',
    'default_wallet_id',
    'preferences'
  ];

  protected $casts = [
    'preferences' => 'array',
  ];

  public function defaultWallet(): BelongsTo
  {
    return $this->belongsTo(Wallet::class, 'default_wallet_id');
  }
}