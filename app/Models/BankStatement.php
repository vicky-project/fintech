<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Telegram\Models\TelegramUser;
use Modules\FinTech\Enums\StatementStatus;
use Modules\FinTech\Traits\HasUuid;

class BankStatement extends Model
{
  use HasUuid;

  protected $table = 'fintech_bank_statements';

  protected $fillable = [
    'user_id',
    'wallet_id',
    'original_filename',
    'file_path',
    'bank_code',
    'status',
    'meta_data',
    'processed_at',
  ];

  protected $casts = [
    'status' => StatementStatus::class,
    'meta_data' => 'array',
    'processed_at' => 'datetime',
  ];

  /**
  * Relasi ke user.
  */
  public function user(): BelongsTo
  {
    return $this->belongsTo(TelegramUser::class);
  }

  /**
  * Relasi ke wallet tujuan import.
  */
  public function wallet(): BelongsTo
  {
    return $this->belongsTo(Wallet::class);
  }

  /**
  * Relasi ke transaksi hasil parsing.
  */
  public function transactions(): HasMany
  {
    return $this->hasMany(StatementTransaction::class, 'statement_id');
  }

  /**
  * Scope untuk statement yang belum selesai.
  */
  public function scopePending($query) {
    return $query->whereNotIn('status', [
      StatementStatus::IMPORTED->value,
      StatementStatus::FAILED->value
    ]);
  }

  /**
  * Scope untuk statement yang siap diimpor.
  */
  public function scopeReadyToImport($query) {
    return $query->where('status', StatementStatus::PARSED->value);
  }

  /**
  * Scope untuk statement milik user tertentu.
  */
  public function scopeForUser($query, int $userId) {
    return $query->where('user_id', $userId);
  }

  /**
  * Update status statement.
  */
  public function updateStatus(StatementStatus $status, ?array $meta = null): void
  {
    $this->status = $status;

    if ($meta !== null) {
      $this->meta_data = array_merge($this->meta_data ?? [], $meta);
    }

    if ($status->isSuccessful() && !$this->processed_at) {
      $this->processed_at = now();
    }

    $this->save();
  }

  /**
  * Cek apakah statement siap diimpor.
  */
  public function isReadyToImport(): bool
  {
    return $this->status->isReadyToImport();
  }

  /**
  * Cek apakah statement gagal.
  */
  public function isFailed(): bool
  {
    return $this->status->isFailed();
  }

  /**
  * Dapatkan URL file (jika disimpan di storage).
  */
  public function getFileUrl(): ?string
  {
    if (!$this->file_path) {
      return null;
    }
    return \Illuminate\Support\Facades\Storage::url($this->file_path);
  }

  /**
  * Hapus file fisik saat model dihapus.
  */
  protected static function booted(): void
  {
    static::deleting(function (BankStatement $statement) {
      if ($statement->file_path) {
        \Illuminate\Support\Facades\Storage::delete($statement->file_path);
      }
    });
  }
}