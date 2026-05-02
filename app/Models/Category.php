<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FinTech\Enums\CategoryType;
use Modules\FinTech\Traits\HasUuid;

class Category extends Model
{
  use HasUuid;

  protected $table = 'fintech_categories';

  protected $fillable = [
    'name',
    'icon',
    'color',
    'type',
    'parent_id',
    'is_system',
    'is_active',
    'metadata',
    'keywords'
  ];

  protected $casts = [
    'type' => CategoryType::class,
    // Casting ke Enum
    'is_system' => 'boolean',
    'is_active' => 'boolean',
    'metadata' => 'array',
    'keywords' => 'array'
  ];

  public function transactions(): HasMany
  {
    return $this->hasMany(Transaction::class);
  }

  public function parent(): BelongsTo
  {
    return $this->belongsTo(Category::class, 'parent_id');
  }

  public function children(): HasMany
  {
    return $this->hasMany(Category::class, 'parent_id');
  }

  public function scopeActive($query) {
    return $query->where('is_active', true);
  }

  public function scopeExpense($query) {
    return $query->whereIn('type', [CategoryType::EXPENSE, CategoryType::BOTH]);
  }

  public function scopeIncome($query) {
    return $query->whereIn('type', [CategoryType::INCOME, CategoryType::BOTH]);
  }

  // Helper method menggunakan Enum
  public function isForIncome(): bool
  {
    return $this->type->isForIncome();
  }

  public function isForExpense(): bool
  {
    return $this->type->isForExpense();
  }
}