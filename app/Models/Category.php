<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
  protected $table = 'fintech_categories';

  protected $fillable = [
    'name',
    'icon',
    'color',
    'is_active'
  ];

  protected $casts = [
    'is_active' => 'boolean',
  ];

  public function transactions(): HasMany
  {
    return $this->hasMany(Transaction::class);
  }

  /**
  * Scope untuk kategori aktif
  */
  public function scopeActive($query) {
    return $query->where('is_active', true);
  }
}