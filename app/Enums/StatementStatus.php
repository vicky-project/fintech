<?php

namespace Modules\FinTech\Enums;

enum StatementStatus: string
{
  case UPLOADED = 'uploaded';
  case DECRYPTED = 'decrypted';
  case PARSED = 'parsed';
  case IMPORTED = 'imported';
  case FAILED = 'failed';

    /**
    * Mendapatkan label untuk tampilan.
    */
    public function label(): string
    {
      return match($this) {
        self::UPLOADED => 'Diunggah',
        self::DECRYPTED => 'Didekripsi',
        self::PARSED => 'Diproses',
        self::IMPORTED => 'Diimpor',
        self::FAILED => 'Gagal',
      };
    }

    /**
    * Mendapatkan array nilai untuk validasi.
    */
    public static function values(): array
    {
      return array_column(self::cases(), 'value');
    }

    /**
    * Cek apakah status sudah selesai (sukses).
    */
    public function isSuccessful(): bool
    {
      return in_array($this, [self::PARSED, self::IMPORTED]);
    }

    /**
    * Cek apakah status gagal.
    */
    public function isFailed(): bool
    {
      return $this === self::FAILED;
    }

    /**
    * Cek apakah statement siap diimpor.
    */
    public function isReadyToImport(): bool
    {
      return $this === self::PARSED;
    }
}