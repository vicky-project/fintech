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

    // Ambil semua kategori aktif dengan tipe sesuai atau 'both'
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

      // Jika kategori memiliki parent, tambahkan skor parent (opsional)
      if ($category->parent_id) {
        $parent = $category->parent;
        if ($parent) {
          $score += $this->calculateMatchScore($lowerDesc, $parent) * 0.3; // bobot lebih kecil
        }
      }

      if ($score > $highestScore) {
        $highestScore = $score;
        $bestMatch = $category;
      }
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

    // 1. Exact match keyword (case-insensitive)
    foreach ($keywords as $keyword) {
      $kw = strtolower($keyword);
      if (str_contains($description, $kw)) {
        // Keyword lebih panjang = lebih spesifik, bobot lebih tinggi
        $score += 10 + (strlen($kw) * 0.5);
      }

      // Pencocokan kata per kata (untuk menghindari partial match yang salah)
      $words = explode(' ', $description);
      foreach ($words as $word) {
        if ($word === $kw) {
          $score += 15;
        }
      }
    }

    // 2. Similaritas teks dengan nama kategori
    similar_text($description, strtolower($category->name), $percent);
    if ($percent > 50) {
      $score += $percent / 10;
    }

    // 3. Deteksi pola khusus (transfer, tagihan, dll) – sudah termasuk dalam keywords

    return $score;
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
        'keywords' => json_encode(['uncategorized', 'tidak terkategori'])
      ]
    );
  }
}