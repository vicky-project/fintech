<?php

namespace Modules\FinTech\Enums;

enum StatementType: string
{
  case DEBIT = 'debit';
  case CREDIT = 'credit';
  case UNKNOWN = 'unknown';

    /**
    * Konversi ke TransactionType.
    */
    public function toTransactionType(): ?TransactionType
    {
      return match($this) {
        self::DEBIT => TransactionType::EXPENSE,
        self::CREDIT => TransactionType::INCOME,
        self::UNKNOWN => null,
      };
    }

    public function label(): string
    {
      return match($this) {
        self::DEBIT => 'Debit (Keluar)',
        self::CREDIT => 'Kredit (Masuk)',
        self::UNKNOWN => 'Tidak Diketahui',
      };
    }

    /**
    * Tentukan tipe dari deskripsi dan nominal.
    * Prioritas:
    * 1. Tanda pada nominal (jika ada)
    * 2. Kata kunci di deskripsi
    * 3. UNKNOWN
    */
    public static function fromDescription(string $description, ?string $amountStr = null): self
    {
      // 1. Deteksi dari tanda nominal
      if ($amountStr !== null) {
        if (str_contains($amountStr, '+')) {
          return self::CREDIT;
        }
        if (str_contains($amountStr, '-')) {
          return self::DEBIT;
        }
      }

      // 2. Deteksi dari kata kunci di deskripsi
      $lowerDesc = strtolower($description);

      $debitKeywords = [
        'debit',
        'db',
        'tarik',
        'withdrawal',
        'pembayaran',
        'payment',
        'biaya',
        'adm',
        'keluar',
        'penarikan',
        'transfer keluar'
      ];

      $creditKeywords = [
        'credit',
        'cr',
        'setor',
        'deposit',
        'masuk',
        'incoming',
        'transfer masuk',
        'penerimaan'
      ];

      foreach ($debitKeywords as $kw) {
        if (str_contains($lowerDesc, $kw)) {
          return self::DEBIT;
        }
      }

      foreach ($creditKeywords as $kw) {
        if (str_contains($lowerDesc, $kw)) {
          return self::CREDIT;
        }
      }

      return self::UNKNOWN;
    }

    /**
    * Mendapatkan array nilai untuk validasi.
    */
    public static function values(): array
    {
      return array_column(self::cases(), 'value');
    }
}