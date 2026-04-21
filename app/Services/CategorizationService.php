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

    // 1. Pencocokan keyword
    foreach ($keywords as $keyword) {
      $kw = strtolower($keyword);
      if (str_contains($description, $kw)) {
        // Bobot lebih tinggi untuk keyword yang lebih panjang (lebih spesifik)
        $score += 10 + (strlen($kw) * 0.5);
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
    similar_text($description, strtolower($category->name), $percent);
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
    $catName = strtolower($category->name);

    // Pola Transfer
    if (str_contains($description, 'transfer') || str_contains($description, 'trsf') || str_contains($description, 'bi fast')) {
      if (str_contains($catName, 'transfer') || str_contains($catName, 'keuangan')) {
        $bonus += 20;
      }
    }

    // Pola Tarik Tunai / ATM
    if (str_contains($description, 'tarik') || str_contains($description, 'atm') || str_contains($description, 'penarikan')) {
      if (str_contains($catName, 'tarik') || str_contains($catName, 'tunai') || str_contains($catName, 'atm')) {
        $bonus += 25;
      }
    }

    // Pola Belanja Online / Marketplace
    if (str_contains($description, 'shopee') || str_contains($description, 'tokopedia') ||
      str_contains($description, 'lazada') || str_contains($description, 'bukalapak')) {
      if (str_contains($catName, 'belanja') || str_contains($catName, 'online')) {
        $bonus += 25;
      }
    }

    // Pola Pembayaran Tagihan (PLN, PDAM, BPJS, Telkom, dll)
    if (preg_match('/\b(pln|pdam|bpjs|telkom|indihome|listrik|air|internet|pulsa)\b/i', $description)) {
      if (str_contains($catName, 'tagihan') || str_contains($catName, 'utilitas')) {
        $bonus += 30;
      }
    }

    // Pola Makanan / Kuliner
    if (preg_match('/\b(resto|restaurant|cafe|kafe|mcd|kfc|pizza|burger|sushi|steak|martabak|bakso|sate|nasi goreng|mie ayam|kopi|coffee)\b/i', $description)) {
      if (str_contains($catName, 'makanan') || str_contains($catName, 'minuman')) {
        $bonus += 30;
      }
    }

    // Pola Transportasi / BBM
    if (preg_match('/\b(bensin|pertamina|shell|spbu|bbm|gojek|grab|taksi|parkir|tol)\b/i', $description)) {
      if (str_contains($catName, 'transportasi') || str_contains($catName, 'bahan bakar')) {
        $bonus += 30;
      }
    }

    // Pola Pendidikan / Kursus
    if (preg_match('/\b(kursus|les|bimbel|sekolah|kuliah|universitas|spp|bootcamp|pelatihan)\b/i', $description)) {
      if (str_contains($catName, 'pendidikan') || str_contains($catName, 'kursus')) {
        $bonus += 30;
      }
    }

    // Pola Kesehatan
    if (preg_match('/\b(dokter|rumah sakit|klinik|apotek|obat|vitamin|bpjs kesehatan|medical)\b/i', $description)) {
      if (str_contains($catName, 'kesehatan')) {
        $bonus += 30;
      }
    }

    // Pola Hiburan / Streaming
    if (preg_match('/\b(netflix|spotify|youtube|disney|hbo|vidio|bioskop|cinema|xxi|cgv)\b/i', $description)) {
      if (str_contains($catName, 'hiburan') || str_contains($catName, 'streaming')) {
        $bonus += 30;
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