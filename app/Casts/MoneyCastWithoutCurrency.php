<?php

namespace Modules\FinTech\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Brick\Money\Money;
use Modules\FinTech\Models\Wallet;

class MoneyCastWithoutCurrency implements CastsAttributes
{
  /**
  * Cast nilai dari database (integer) ke Money object.
  */
  public function get($model, string $key, $value, array $attributes) {
    if ($value === null) {
      return null;
    }

    $currency = $this->getCurrency($model, $attributes);

    return Money::ofMinor((int) $value, $currency);
  }

  /**
  * Cast Money object ke integer untuk disimpan di database.
  */
  public function set($model, string $key, $value, array $attributes) {
    if ($value instanceof Money) {
      return $value->getMinorAmount()->toInt();
    }

    if (is_numeric($value)) {
      return (int) round((float) $value * 100);
    }

    return 0;
  }

  /**
  * Dapatkan kode mata uang dari model.
  */
  protected function getCurrency($model, array $attributes): string
  {
    // 1. Jika model memiliki relasi wallet langsung (Transaction)
    if (method_exists($model, 'wallet') && !empty($attributes['wallet_id'])) {
      $wallet = $model->wallet ?? Wallet::find($attributes['wallet_id']);
      return $wallet?->currency ?? config('fintech.default_currency', 'IDR');
    }

    // 2. Jika model adalah StatementTransaction, ambil dari statement -> wallet
    if (method_exists($model, 'statement') && !empty($attributes['statement_id'])) {
      $statement = $model->statement ?? \Modules\FinTech\Models\BankStatement::find($attributes['statement_id']);
      if ($statement && $statement->wallet_id) {
        $wallet = $statement->wallet ?? Wallet::find($statement->wallet_id);
        return $wallet?->currency ?? config('fintech.default_currency', 'IDR');
      }
    }

    // 3. Fallback ke IDR
    return config('fintech.default_currency', 'IDR');
  }
}