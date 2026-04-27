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
    // Default fallback
    $defaultPrecision = 0;
    $defaultDecimalMark = ',';
    $defaultThousandsSep = '.';
    $defaultSymbol = 'Rp';
    $defaultSymbolFirst = true;

    $amountFloat = $this->getAmountFloat();

    // Ambil detail mata uang dari dompet
    if ($this->fromWallet && $this->fromWallet->currencyDetails) {
      $currency = $this->fromWallet->currencyDetails;
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