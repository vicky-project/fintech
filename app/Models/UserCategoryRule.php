<?php
namespace Modules\FinTech\Models;

use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\FinTech\Traits\HasUuid;
use Modules\Telegram\Models\TelegramUser;

class UserCategoryRule extends Model
{
  use HasUuid;

  protected $table = 'fintech_user_settings';

  protected $fillable = [
    'user_id',
    'uuid',
    'category_id',
    'keyword',
    'weight',
    'occurrences',
    'last_used_at',
  ];

  protected $casts = [
    'weight' => 'float',
    'occurrences' => 'integer',
    'last_used_at' => 'datetime',
  ];

  public function user() {
    return $this->belongsTo(TelegramUser::class, 'user_id');
  }

  public function category() {
    return $this->belongsTo(Category::class, 'category_id');
  }

  /**
  * Belajar dari koreksi pengguna.
  */
  public static function learn(int $userId, string $description, int $categoryId): void
  {
    $words = self::extractImportantWords($description);
    foreach ($words as $word) {
      $rule = self::firstOrNew([
        'user_id' => $userId,
        'keyword' => $word,
      ]);

      if ($rule->exists && $rule->category_id == $categoryId) {
        $rule->increment('weight', 10);
        $rule->increment('occurrences');
      } else {
        $rule->category_id = $categoryId;
        $rule->weight = 10;
        $rule->occurrences = 1;
      }
      $rule->last_used_at = now();
      $rule->save();
    }
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
    return array_values(array_unique(array_filter($words, fn($w) => strlen($w) > 3 && !in_array($w, $stopWords))));
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