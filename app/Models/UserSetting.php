<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class UserSetting extends Model
{
  protected $table = 'fintech_user_settings';

  protected $fillable = [
    'user_id',
    'default_currency',
    'default_wallet_id',
    'preferences',
    'pin',
    'pin_enabled',
    'pin_attempts',
    'locked_until'
  ];

  protected $casts = [
    'preferences' => 'array',
    'pin_enabled' => 'boolean',
    'locked_until' => 'datetime'
  ];

  protected $hidden = ['pin'];

  public function defaultWallet(): BelongsTo
  {
    return $this->belongsTo(Wallet::class, 'default_wallet_id');
  }

  /**
  * Mutator untuk auto-hash PIN.
  */
  public function setPinAttribute(?string $value): void
  {
    if ($value !== null) {
      $this->attributes['pin'] = Hash::make($value);
    } else {
      $this->attributes['pin'] = null;
    }
  }

  /**
  * Verifikasi PIN yang diinput user.
  */
  public function verifyPin(string $inputPin): bool
  {
    if (!$this->pin) {
      return false;
    }
    return Hash::check($inputPin, $this->pin);
  }
}