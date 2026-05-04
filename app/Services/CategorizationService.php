<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Category;
use Modules\FinTech\Enums\CategoryType;
use Modules\FinTech\Enums\StatementType;

class CategorizationService
{
  /**
  * Tentukan kategori terbaik untuk transaksi statement.
  */
  public function categorize(string $description, StatementType $statementType): ?Category
  {
    $targetType = $statementType === StatementType::CREDIT
    ? CategoryType::INCOME
    : CategoryType::EXPENSE;

    $categories = Category::active()
    ->where(function ($query) use ($targetType) {
      $query->where('type', $targetType)
      ->orWhere('type', CategoryType::BOTH);
    })
    ->get();

    $bestMatch = null;
    $highestScore = 0;
    $lowerDesc = strtolower($description);

    foreach ($categories as $category) {
      $score = $this->calculateMatchScore($lowerDesc, $category);

      if ($category->parent_id) {
        $parent = $category->parent;
        if ($parent) {
          $score += $this->calculateMatchScore($lowerDesc, $parent) * 0.2;
        }
      }

      if ($score > $highestScore) {
        $highestScore = $score;
        $bestMatch = $category;
      }
    }

    // Jika tidak ada yang cocok, gunakan fallback berbasis aturan sederhana
    if (!$bestMatch) {
      $bestMatch = $this->ruleBasedFallback($lowerDesc, $targetType);
    }

    return $bestMatch ?? $this->getDefaultCategory($targetType);
  }

  /**
  * Hitung skor kecocokan antara deskripsi dan kategori.
  */
  private function calculateMatchScore(string $description, Category $category): float
  {
    $score = 0;
    $keywords = $category->keywords ?? [];
    $lowerDesc = strtolower($description);

    // 1. Pencocokan keyword
    foreach ($keywords as $keyword) {
      $kw = strtolower($keyword);
      if (str_contains($lowerDesc, $kw)) {
        // Bobot lebih tinggi untuk keyword yang lebih panjang (lebih spesifik)
        $score += 10 + (strlen($kw) * 0.5);
      }

      if ($kw === 'biaya' && preg_match('/\bbiaya\b/', $lowerDesc)) {
        $score += 20;
      }

      // Pencocokan kata utuh (untuk menghindari partial match yang salah)
      $words = preg_split('/[\s,.-]+/', $description);
      foreach ($words as $word) {
        if ($word === $kw) {
          $score += 15;
        }
      }
    }

    // 2. Similaritas dengan nama kategori
    similar_text($lowerDesc, strtolower($category->name), $percent);
    if ($percent > 50) {
      $score += $percent / 5;
    }

    // 3. Pola khusus yang terdeteksi dari deskripsi (bonus skor)
    $score += $this->patternBonus($description, $category);

    return $score;
  }

  /**
  * Memberikan bonus skor berdasarkan pola deskripsi yang umum.
  */
  private function patternBonus(string $description, Category $category): float
  {
    $bonus = 0;
    $lowerDesc = strtolower($description);
    $catName = strtolower($category->name);

    // === BIAYA / ADMIN ===
    if (str_contains($lowerDesc, 'biaya') || str_contains($lowerDesc, 'admin') || str_contains($lowerDesc, 'administrasi') || str_contains($lowerDesc, 'fee')) {
      if (str_contains($catName, 'biaya') || str_contains($catName, 'admin') || str_contains($catName, 'pajak')) {
        $bonus += 50;
      }
    }

    // === TRANSFER ===
    if (preg_match('/\b(transfer|trsf|trf|kirim|bifast)\b/i', $lowerDesc)) {
      if (str_contains($catName, 'transfer') || str_contains($catName, 'keuangan')) {
        $bonus += 20;
      }
    }

    // === TARIK TUNAI / ATM ===
    if (str_contains($lowerDesc, 'tarik') || str_contains($lowerDesc, 'atm') || str_contains($lowerDesc, 'penarikan')) {
      if (str_contains($catName, 'tarik') || str_contains($catName, 'tunai') || str_contains($catName, 'atm')) {
        $bonus += 25;
      }
    }

    // === BELANJA ONLINE / MARKETPLACE ===
    if (str_contains($lowerDesc, 'shopee') || str_contains($lowerDesc, 'tokopedia') ||
      str_contains($lowerDesc, 'lazada') || str_contains($lowerDesc, 'bukalapak')) {
      if (str_contains($catName, 'belanja') || str_contains($catName, 'online')) {
        $bonus += 25;
      }
    }

    // === TAGIHAN (PLN, PDAM, BPJS, Telkom, Indihome, Pulsa) ===
    if (preg_match('/\b(pln|pdam|bpjs|telkom|indihome|listrik|air|internet|pulsa)\b/i', $lowerDesc)) {
      if (str_contains($catName, 'tagihan') || str_contains($catName, 'utilitas')) {
        $bonus += 30;
      }
    }

    // === MAKANAN / KULINER ===
    if (preg_match('/\b(resto|restaurant|cafe|kafe|mcd|kfc|pizza|burger|sushi|steak|martabak|bakso|sate|nasi goreng|mie ayam|kopi|coffee)\b/i', $lowerDesc)) {
      if (str_contains($catName, 'makanan') || str_contains($catName, 'minuman')) {
        $bonus += 30;
      }
    }

    // === TRANSPORTASI / BBM ===
    if (preg_match('/\b(bensin|pertamina|shell|spbu|bbm|gojek|grab|taksi|parkir|tol)\b/i', $lowerDesc)) {
      if (str_contains($catName, 'transportasi') || str_contains($catName, 'bahan bakar')) {
        $bonus += 30;
      }
    }

    // === PENDIDIKAN / KURSUS ===
    if (preg_match('/\b(kursus|les|bimbel|sekolah|kuliah|universitas|spp|bootcamp|pelatihan)\b/i', $lowerDesc)) {
      if (str_contains($catName, 'pendidikan') || str_contains($catName, 'kursus')) {
        $bonus += 30;
      }
    }

    // === KESEHATAN ===
    if (preg_match('/\b(dokter|rumah sakit|klinik|apotek|obat|vitamin|bpjs kesehatan|medical)\b/i', $lowerDesc)) {
      if (str_contains($catName, 'kesehatan')) {
        $bonus += 30;
      }
    }

    // === HIBURAN / STREAMING ===
    if (preg_match('/\b(netflix|spotify|youtube|disney|hbo|vidio|bioskop|cinema|xxi|cgv)\b/i', $lowerDesc)) {
      if (str_contains($catName, 'hiburan') || str_contains($catName, 'streaming')) {
        $bonus += 30;
      }
    }

    // === POLA SPESIFIK BNI ===
    // BY TRX BIFAST → Biaya Admin
    if (str_contains($lowerDesc, 'by trx bifast')) {
      if ($catName === 'biaya admin & pajak') {
        $bonus += 60;
      }
    }

    // TRF/PAY/TOP-UP ECHANNEL KARTU → bisa Pulsa, E-Wallet, atau Transfer
    if (str_contains($lowerDesc, 'trf/pay/top-up echannel kartu')) {
      if (str_contains($catName, 'pulsa') || str_contains($catName, 'e-wallet')) {
        $bonus += 30;
      } elseif (str_contains($catName, 'transfer')) {
        $bonus += 15;
      }
    }

    // TRANSFER KE ESPAY → E-Wallet
    if (str_contains($lowerDesc, 'transfer ke espay')) {
      if (str_contains($catName, 'e-wallet') || str_contains($catName, 'belanja')) {
        $bonus += 35;
      }
    }

    // TRANSFER KE AIRPAY → Shopee/ Belanja
    if (str_contains($lowerDesc, 'airpay international')) {
      if (str_contains($catName, 'e-wallet') || str_contains($catName, 'belanja')) {
        $bonus += 35;
      }
    }

    // TARIK TUNAI KARTU (BNI) → Tarik Tunai
    if (str_contains($lowerDesc, 'tarik tunai kartu')) {
      if (str_contains($catName, 'tarik tunai') || str_contains($catName, 'atm')) {
        $bonus += 40;
      }
    }

    // SETOR TUNAI KARTU (BNI) → Pendapatan/Setor Tunai
    if (str_contains($lowerDesc, 'setor tunai kartu')) {
      if (str_contains($catName, 'setor tunai') || str_contains($catName, 'pendapatan')) {
        $bonus += 40;
      }
    }

    // TRANSFER KE GOPAY / OVO / DANA → E-Wallet
    if (preg_match('/\btransfer ke (gopay|ovo|dana|linkaja)\b/i', $lowerDesc)) {
      if (str_contains($catName, 'e-wallet')) {
        $bonus += 35;
      }
    }

    // QRIS → Belanja atau E-Wallet
    if (str_contains($lowerDesc, 'qris')) {
      if (str_contains($catName, 'belanja') || str_contains($catName, 'e-wallet')) {
        $bonus += 30;
      }
    }

    // Pembayaran ke SIMSEM/POS PURCHASE → Belanja
    if (str_contains($lowerDesc, 'simesem pos') || str_contains($lowerDesc, 'purchase')) {
      if (str_contains($catName, 'belanja')) {
        $bonus += 25;
      }
    }

    return $bonus;
  }

  /**
  * Fallback berbasis aturan sederhana.
  */
  private function ruleBasedFallback(string $description, CategoryType $targetType): ?Category
  {
    $lower = strtolower($description);
    $categories = Category::active()->where('type', $targetType)->get();

    foreach ($categories as $cat) {
      $name = strtolower($cat->name);
      // Cek apakah deskripsi mengandung substring dari nama kategori
      if (str_contains($lower, $name)) {
        return $cat;
      }
      // Cek apakah nama kategori muncul sebagai kata utuh
      if (preg_match("/\b" . preg_quote($name, '/') . "\b/", $lower)) {
        return $cat;
      }
    }

    return null;
  }

  private function getDefaultCategory(CategoryType $type): Category
  {
    $name = $type === CategoryType::INCOME ? 'Lainnya (Pemasukan)' : 'Lainnya';
    return Category::firstOrCreate(
      ['name' => $name, 'type' => $type],
      [
        'icon' => 'bi-question',
        'color' => '#6c757d',
        'is_system' => true,
        'keywords' => ['uncategorized', 'tidak terkategori']
      ]
    );
  }
}