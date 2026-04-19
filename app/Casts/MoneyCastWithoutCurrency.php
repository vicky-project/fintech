<?php

namespace Modules\FinTech\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Brick\Money\Money;
use Modules\FinTech\Models\Wallet;

class MoneyCastWithoutCurrency implements CastsAttributes
{
  /**
  * Cast the stored value to Money.
  */
  public function get($model, string $key, $value, array $attributes) {
    if ($value === null) {
      return null;
    }

    // Dapatkan mata uang dari wallet
    $currency = 'IDR';
    if ($model->wallet_id) {
      $wallet = $model->wallet ?? Wallet::find($model->wallet_id);
      $currency = $wallet?->currency ?? 'IDR';
    }

    return Money::ofMinor($value, $currency);
  }

  /**
  * Prepare the Money object for storage.
  */
  public function set($model, string $key, $value, array $attributes) {
    if ($value instanceof Money) {
      return $value->getMinorAmount()->toInt();
    }

    if (is_numeric($value)) {
      // Asumsikan nilai float dalam mata uang utama
      return (int) round($value * 100);
    }

    return 0;
  }
}