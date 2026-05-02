<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FinTech\Enums\StatementType;
use Modules\FinTech\Enums\TransactionType;
use Modules\FinTech\Casts\MoneyCastWithoutCurrency;
use Brick\Money\Money;
use Modules\FinTech\Traits\HasUuid;

class StatementTransaction extends Model
{
  use HasUuid;

  protected $table = 'fintech_statement_transactions';

  protected $fillable = [
    'statement_id',
    'uuid',
    'transaction_date',
    'description',
    'amount',
    'type',
    'category_id',
    'is_imported',
    'raw_data',
  ];

  protected $casts = [
    'transaction_date' => 'date',
    'amount' => MoneyCastWithoutCurrency::class,
    'type' => StatementType::class,
    'is_imported' => 'boolean',
    'raw_data' => 'array',
  ];

  /**
  * Relasi ke statement.
  */
  public function statement(): BelongsTo
  {
    return $this->belongsTo(BankStatement::class, 'statement_id');
  }

  /**
  * Relasi ke kategori.
  */
  public function category(): BelongsTo
  {
    return $this->belongsTo(Category::class);
  }

  /**
  * Scope untuk transaksi yang belum diimpor.
  */
  public function scopeNotImported($query) {
    return $query->where('is_imported', false);
  }

  /**
  * Scope untuk transaksi yang sudah diimpor.
  */
  public function scopeImported($query) {
    return $query->where('is_imported', true);
  }

  /**
  * Konversi ke TransactionType (jika type diketahui).
  */
  public function toTransactionType(): ?TransactionType
  {
    return $this->type?->toTransactionType();
  }

  /**
  * Dapatkan amount dengan tanda (+/-) sesuai tipe.
  */
  public function getSignedAmount(): float
  {
    if ($this->type === StatementType::DEBIT) {
      return -abs($this->getAmountFloat());
    }
    return abs($this->getAmountFloat());
  }

  /**
  * Helper untuk mendapatkan amount dalam float.
  */
  public function getAmountFloat(): float
  {
    return $this->amount?->getAmount()->toFloat() ?? 0.0;
  }

  /**
  * Format amount ke string dengan mata uang yang sesuai.
  */
  public function getFormattedAmount(): string
  {
    if (!$this->amount) {
      return 'Rp 0';
    }
    return $this->amount->formatTo('id_ID');
  }

  /**
  * Tandai sebagai sudah diimpor.
  */
  public function markAsImported(): void
  {
    $this->is_imported = true;
    $this->save();
  }

  /**
  * Tandai sebagai belum diimpor.
  */
  public function markAsNotImported(): void
  {
    $this->is_imported = false;
    $this->save();
  }
}