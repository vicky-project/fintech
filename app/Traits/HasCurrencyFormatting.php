<?php

namespace Modules\FinTech\Traits;

trait HasCurrencyFormatting
{
  /**
  * Default format rules (fallback).
  */
  protected function defaultCurrencyFormat(): array
  {
    return [
      'precision' => 0,
      'decimal_mark' => ',',
      'thousands_separator' => '.',
      'symbol' => 'Rp',
      'symbol_first' => true,
    ];
  }

  /**
  * Get currency formatting rules from the related wallet.
  * Override this if the relation name is not 'wallet'.
  */
  protected function getCurrencyRules(): array
  {
    $default = $this->defaultCurrencyFormat();

    if (method_exists($this, 'wallet') && $this->wallet && $this->wallet->currencyDetails) {
      $currency = $this->wallet->currencyDetails;
      return [
        'precision' => $currency->precision ?? $default['precision'],
        'decimal_mark' => $currency->decimal_mark ?? $default['decimal_mark'],
        'thousands_separator' => $currency->thousands_separator ?? $default['thousands_separator'],
        'symbol' => $currency->symbol ?? $default['symbol'],
        'symbol_first' => $currency->symbol_first ?? $default['symbol_first'],
      ];
    }

    return $default;
  }

  /**
  * Format a numeric value into a currency string.
  */
  public function formatCurrency(float $amount): string
  {
    $rules = $this->getCurrencyRules();

    $formattedNumber = number_format(
      $amount,
      $rules['precision'],
      $rules['decimal_mark'],
      $rules['thousands_separator']
    );

    return $rules['symbol_first']
    ? $rules['symbol'] . ' ' . $formattedNumber
    : $formattedNumber . ' ' . $rules['symbol'];
  }

  /**
  * Format the model's amount field using currency rules.
  * Override if the amount field name is different.
  */
  public function getFormattedAmount(): string
  {
    if (method_exists($this, 'getAmountFloat')) {
      return $this->formatCurrency($this->getAmountFloat());
    }
    return $this->formatCurrency(0);
  }
}