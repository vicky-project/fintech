<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FinTech\Casts\MoneyCastWithoutCurrency;
use Modules\FinTech\Enums\TransactionType;
use Brick\Money\Money;

class Transaction extends Model
{
  use SoftDeletes;

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
      'amount' => MoneyCastWithoutCurrency::class,
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
    return $query->where('type', TransactionType::EXPENSE);
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
    // Default fallback
    $defaultPrecision = 0;
    $defaultDecimalMark = ',';
    $defaultThousandsSep = '.';
    $defaultSymbol = 'Rp';
    $defaultSymbolFirst = true;

    $amountFloat = $this->getAmountFloat();

    // Ambil detail mata uang dari dompet
    if ($this->wallet && $this->wallet->currencyDetails) {
      $currency = $this->wallet->currencyDetails;
      $precision = $currency->precision ?? $defaultPrecision;
      $decimalMark = $currency->decimal_mark ?? $defaultDecimalMark;
      $thousandsSep = $currency->thousands_separator ?? $defaultThousandsSep;
      $symbol = $currency->symbol ?? $defaultSymbol;
      $symbolFirst = $currency->symbol_first ?? $defaultSymbolFirst;
    } else {
      // Fallback ke data default
      $precision = $defaultPrecision;
      $decimalMark = $defaultDecimalMark;
      $thousandsSep = $defaultThousandsSep;
      $symbol = $defaultSymbol;
      $symbolFirst = $defaultSymbolFirst;
    }

    // Format angka
    $formattedNumber = number_format($amountFloat, $precision, $decimalMark, $thousandsSep);

    // Susun simbol dan angka
    return $symbolFirst
    ? $symbol . ' ' . $formattedNumber
    : $formattedNumber . ' ' . $symbol;
  }
}