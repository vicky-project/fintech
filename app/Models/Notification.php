<?php

namespace Modules\FinTech\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\FinTech\Enums\NotificationType;
use Modules\FinTech\Traits\HasUuid;

class Notification extends Model
{
  use HasUuid;

  protected $table = 'fintech_notifications';

  protected $fillable = [
    'user_id',
    'uuid',
    'type',
    'title',
    'message',
    'data',
    'is_read',
    'read_at',
  ];

  protected $casts = [
    'type' => NotificationType::class,
    'data' => 'array',
    'is_read' => 'boolean',
    'read_at' => 'datetime',
  ];

  public function markAsRead(): void
  {
    $this->update([
      'is_read' => true,
      'read_at' => now(),
    ]);
  }

  /**
  * Scope untuk notifikasi yang belum dibaca.
  */
  public function scopeUnread($query) {
    return $query->where('is_read', false);
  }
}