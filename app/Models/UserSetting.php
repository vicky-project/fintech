<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Modules\FinTech\Traits\HasUuid;

class UserSetting extends Model
{
  use HasUuid;

  protected $table = 'fintech_user_settings';

  protected $fillable = [
    'user_id',
    'uuid',
    'default_currency',
    'default_wallet_id',
    'preferences',
    'pin',
    'pin_enabled',
    'pin_attempts',
    'locked_until',
    'pin_verified_at',
    'google_access_token',
    'google_refresh_token',
    'google_token_expires_at',
    'google_spreadsheet_id'
  ];

  protected $casts = [
    'preferences' => 'array',
    'pin_enabled' => 'boolean',
    'pin_attempts' => 'integer',
    'locked_until' => 'datetime',
    'pin_verified_at' => 'datetime',
    'google_token_expires_at' => 'datetime',
    'google_access_token' => 'encrypted'
    'google_refresh_token' => 'encrypted'
  ];

  protected $hidden = [
    'pin',
    'google_access_token',
    'google_refresh_token',
    'google_token_expires_at',
    'google_spreadsheet_id'
  ];

  public function defaultWallet(): BelongsTo
  {
    return $this->belongsTo(Wallet::class, 'default_wallet_id');
  }

  /**
  * Mutator untuk auto-hash PIN.
  */
  public function setPinAttribute(?string $value): void
  {
    if (!empty($value)) {
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
    if (empty($this->pin)) {
      return false;
    }
    return Hash::check($inputPin, $this->pin);
  }

  /**
  * Catat percobaan PIN gagal.
  */
  public function recordFailedAttempt(): void
  {
    $this->pin_attempts = ($this->pin_attempts ?? 0) + 1;

    if ($this->pin_attempts >= 5) {
      $this->locked_until = now()->addMinutes(15);
    }

    $this->save();
  }

  /**
  * Reset percobaan PIN setelah berhasil.
  */
  public function resetAttempts(): void
  {
    $this->pin_attempts = 0;
    $this->locked_until = null;
    $this->save();
  }

  /**
  * Cek apakah akun sedang terkunci.
  */
  public function isLocked(): bool
  {
    if (empty($this->locked_until)) {
      return false;
    }
    if (now()->gte($this->locked_until)) {
      $this->resetAttempts();
      return false;
    }
    return true;
  }

  /**
  * Dapatkan sisa waktu kunci dalam format yang bisa dibaca manusia.
  */
  public function getLockoutRemaining(): ?string
  {
    if (!$this->isLocked()) {
      return null;
    }
    return now()->diffForHumans($this->locked_until, true);
  }
}