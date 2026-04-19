<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Elegantly\Money\MoneyCast;
use Brick\Money\Money;
use Modules\Telegram\Models\TelegramUser;
use Nnjeim\World\Models\Currency;

class Wallet extends Model
{
  protected $table = 'fintech_wallets';

  protected $fillable = [
    'user_id',
    'name',
    'balance',
    'currency',
    'description',
    'is_active'
  ];

  protected function casts(): array
  {
    return [
      'balance' => MoneyCast::of('currency'),
      'is_active' => 'boolean',
    ];
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(TelegramUser::class);
  }

  public function transactions(): HasMany
  {
    return $this->hasMany(Transaction::class);
  }

  /**
  * Relasi ke tabel world_currencies
  * Kolom 'currency' di wallets merujuk ke 'code' di world_currencies
  */
  public function currencyDetails(): BelongsTo
  {
    return $this->belongsTo(Currency::class, 'currency', 'code');
  }

  public function deposit(Money $amount): void
  {
    if (!$amount->isPositiveOrZero()) {
      throw new \Exception('Jumlah deposit harus positif');
    }
    $this->balance = $this->balance->plus($amount);
    $this->save();
  }

  public function withdraw(Money $amount): void
  {
    if (!$amount->isPositive()) {
      throw new \Exception('Jumlah penarikan harus positif');
    }
    if ($this->balance->isLessThan($amount)) {
      throw new \Exception('Saldo tidak mencukupi');
    }
    $this->balance = $this->balance->minus($amount);
    $this->save();
  }

  public function getBalanceFloat(): float
  {
    return $this->balance->getAmount()->toFloat();
  }

  public function getFormattedBalance(): string
  {
    // Bisa menggunakan locale berdasarkan currency
    return $this->balance->formatTo('id_ID');
  }

  /**
  * Get formatted balance with proper currency symbol
  */
  public function getFormattedBalanceWithSymbol(): string
  {
    $symbol = $this->currencyDetails?->symbol ?? $this->currency;
    return $symbol . ' ' . number_format($this->getBalanceFloat(), 2);
  }
}