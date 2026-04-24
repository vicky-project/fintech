<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FinTech\Enums\PeriodType;
use Modules\FinTech\Casts\MoneyCastWithoutCurrency;

class Budget extends Model
{
  protected $table = 'fintech_budgets';

  protected $fillable = [
    'user_id',
    'category_id',
    'wallet_id',
    'amount',
    'period_type',
    'is_active'
  ];

  protected $casts = [
    'amount' => MoneyCastWithoutCurrency::class,
    'period_type' => PeriodType::class,
    'is_active' => 'boolean',
  ];

  public function category(): BelongsTo
  {
    return $this->belongsTo(Category::class);
  }

  public function wallet(): BelongsTo
  {
    return $this->belongsTo(Wallet::class);
  }

  // Helper untuk mendapatkan float amount
  public function getAmountFloat(): float
  {
    return $this->amount?->getAmount()->toFloat() ?? 0.0;
  }

  // Format amount untuk tampilan
  public function getFormattedAmount(): string
  {
    if (!$this->amount) return 'Rp 0';
    return $this->amount->formatTo('id_ID');
  }

  /**
  * Hitung total pengeluaran untuk kategori ini pada periode berjalan.
  */
  public function getCurrentSpending(): float
  {
    $query = Transaction::expense()
    ->where('category_id', $this->category_id)
    ->whereHas('wallet', fn($q) => $q->where('user_id', $this->user_id));

    if ($this->wallet_id) {
      $query->where('wallet_id', $this->wallet_id);
    }

    if ($this->period_type === PeriodType::MONTHLY) {
      $query->whereMonth('transaction_date', now()->month)
      ->whereYear('transaction_date', now()->year);
    } else {
      $query->whereYear('transaction_date', now()->year);
    }

    return $query->sum('amount') / 100; // amount disimpan dalam satuan terkecil
  }

  /**
  * Dapatkan persentase penggunaan (0-100).
  */
  public function getPercentage(): float
  {
    $amountFloat = $this->getAmountFloat();
    if ($amountFloat <= 0) return 0;
    return min(100, round(($this->getCurrentSpending() / $amountFloat) * 100, 1));
  }

  /**
  * Cek apakah budget sudah terlampaui.
  */
  public function isOverspent(): bool
  {
    return $this->getPercentage() >= 100;
  }

  /**
  * Cek apakah budget hampir habis (>80%).
  */
  public function isNearLimit(): bool
  {
    $pct = $this->getPercentage();
    return $pct >= 80 && $pct < 100;
  }
}