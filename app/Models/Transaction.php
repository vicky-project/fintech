<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Elegantly\Money\MoneyCast;
use Brick\Money\Money;
use Modules\FinTech\Enums\TransactionType;

class Transaction extends Model
{
  protected $table = 'fintech_transactions';

  protected $fillable = [
    'wallet_id',
    'category_id',
    'type',
    'amount',
    'description',
    'transaction_date',
    'metadata'
  ];

  protected function casts(): array
  {
    return [
      'amount' => MoneyCast::of(),
      'transaction_date' => 'date',
      'metadata' => 'array',
      'type' => TransactionType::class,
      // Casting ke Enum
    ];
  }

  public function wallet(): BelongsTo
  {
    return $this->belongsTo(Wallet::class);
  }

  public function category(): BelongsTo
  {
    return $this->belongsTo(Category::class);
  }

  // Scopes menggunakan Enum
  public function scopeOfType($query, TransactionType $type) {
    return $query->where('type', $type->value);
  }

  public function scopeIncome($query) {
    return $query->where('type', TransactionType::INCOME->value);
  }

  public function scopeExpense($query) {
    return $query->where('type', TransactionType::EXPENSE->value);
  }

  public function scopeTransfer($query) {
    return $query->where('type', TransactionType::TRANSFER->value);
  }

  public function scopeThisMonth($query) {
    return $query->whereMonth('transaction_date', now()->month)
    ->whereYear('transaction_date', now()->year);
  }

  // Helper methods
  public function getAmountFloat(): float
  {
    return $this->amount->getAmount()->toFloat();
  }

  public function getFormattedAmount(): string
  {
    return $this->amount->formatTo('id_ID');
  }
}