<?php

namespace Modules\FinTech\Enums;

enum StatementType: string
{
  case DEBIT = 'debit'; // Pengeluaran
  case CREDIT = 'credit'; // Pemasukan
  case UNKNOWN = 'unknown'; // Belum diketahui

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

    public static function fromDescription(string $description, float $amount): self
    {
      // Deteksi dari kata kunci di deskripsi
      $keywords = [
        'debit' => self::DEBIT,
        'db' => self::DEBIT,
        'tarik' => self::DEBIT,
        'withdrawal' => self::DEBIT,
        'pembayaran' => self::DEBIT,
        'payment' => self::DEBIT,
        'transfer keluar' => self::DEBIT,
        'credit' => self::CREDIT,
        'cr' => self::CREDIT,
        'setor' => self::CREDIT,
        'deposit' => self::CREDIT,
        'transfer masuk' => self::CREDIT,
        'incoming' => self::CREDIT,
      ];

      $lowerDesc = strtolower($description);
      foreach ($keywords as $keyword => $type) {
        if (str_contains($lowerDesc, $keyword)) {
          return $type;
        }
      }

      // Jika nominal negatif, anggap debit
      if ($amount < 0) {
        return self::DEBIT;
      }

      return self::UNKNOWN;
    }
}