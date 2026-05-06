<?php
namespace Modules\FinTech\Models;

use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\FinTech\Traits\HasUuid;
use Modules\Telegram\Models\TelegramUser;

class UserCategoryRule extends Model
{
  use HasUuid;

  protected $table = 'fintech_user_category_rules';

  protected $fillable = [
    'user_id',
    'uuid',
    'category_id',
    'keyword',
    'weight',
    'occurrences',
    'confidence',
    'last_used_at',
  ];

  protected $casts = [
    'weight' => 'float',
    'occurrences' => 'integer',
    'confidence' => 'float',
    'last_used_at' => 'datetime',
  ];

  public function user() {
    return $this->belongsTo(TelegramUser::class, 'user_id');
  }

  public function category() {
    return $this->belongsTo(Category::class, 'category_id');
  }

  /**
  * Belajar dari koreksi / konfirmasi pengguna.
  */
  public static function learn(int $userId, string $description, int $newCategoryId, ?int $oldCategoryId = null): void
  {
    $words = self::extractImportantWords($description);

    // Kurangi confidence aturan lama jika kategori diubah
    if ($oldCategoryId && $oldCategoryId !== $newCategoryId) {
      foreach ($words as $word) {
        $rule = self::where('user_id', $userId)
        ->where('keyword', $word)
        ->where('category_id', $oldCategoryId)
        ->first();
        if ($rule) {
          $rule->confidence = max(0.1, $rule->confidence - 0.2);
          $rule->save();
        }
      }
    }

    // Perbarui aturan baru
    foreach ($words as $word) {
      $rule = self::firstOrNew([
        'user_id' => $userId,
        'keyword' => $word,
      ]);

      if ($rule->exists && $rule->category_id == $newCategoryId) {
        $rule->weight += 10;
        $rule->occurrences = ($rule->occurrences ?? 0) + 1;
        $rule->confidence = min(1.0, $rule->confidence + 0.1);
      } else {
        $rule->category_id = $newCategoryId;
        $rule->weight = 10;
        $rule->occurrences = 1;
        $rule->confidence = 0.5;
      }
      $rule->last_used_at = now();
      $rule->save();
    }
  }

  /**
  * Skor personalisasi untuk setiap kategori berdasarkan kata-kata dalam deskripsi.
  */
  public static function getPersonalizedScores(int $userId, string $description): array
  {
    $words = self::extractImportantWords($description);
    if (empty($words)) return [];

    $rules = self::where('user_id', $userId)
    ->whereIn('keyword', $words)
    ->get();

    $scores = [];
    foreach ($rules as $rule) {
      $cid = $rule->category_id;
      $scores[$cid] = ($scores[$cid] ?? 0)
      + ($rule->weight * $rule->confidence * log($rule->occurrences + 1));
    }

    return $scores;
  }

  /**
  * Ekstrak kata penting dari deskripsi.
  */
  public static function extractImportantWords(string $description): array
  {
    $stopWords = [
      'dan',
      'di',
      'ke',
      'dari',
      'untuk',
      'dengan',
      'pada',
      'ini',
      'itu',
      'adalah',
      'yang',
      'dalam',
      'sebagai',
      'atau',
      'oleh',
      'saya',
      'anda'
    ];
    $words = preg_split('/[\s,.-]+/', strtolower($description));
    return array_values(array_unique(
      array_filter($words, fn($w) => strlen($w) > 3 && !in_array($w, $stopWords))
    ));
  }

  /**
  * Ambil aturan yang cocok untuk deskripsi.
  */
  public static function getMatchingRules(int $userId, string $description): Collection
  {
    $words = self::extractImportantWords($description);
    return self::where('user_id', $userId)
    ->whereIn('keyword', $words)
    ->get();
  }
}