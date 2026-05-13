<?php

namespace Modules\FinTech\Traits;

use Illuminate\Support\Facades\Cache;

trait HasCache
{
  /**
  * Indikasi apakah menggunakan tagging (override di class jika perlu)
  */
  protected function useCacheTags(): bool
  {
    return Cache::getStore() instanceof \Illuminate\Cache\TaggableStore;
  }

  /**
  * Dapatkan tag untuk cache (override untuk cache global)
  */
  protected function getCacheTag(): ?string
  {
    return null;
  }

  /**
  * Dapatkan prefix untuk key (override)
  */
  protected function getCachePrefix(): string
  {
    return '';
  }

  /**
  * Buat key cache dengan prefix
  */
  protected function cacheKey(string $suffix): string
  {
    $prefix = $this->getCachePrefix();
    return $prefix ? $prefix . '_' . $suffix : $suffix;
  }

  /**
  * Ambil atau simpan cache (global)
  */
  public function rememberCache(string $suffix, int $ttl, callable $callback): mixed
  {
    $key = $this->cacheKey($suffix);
    $tag = $this->getCacheTag();

    if ($tag && $this->useCacheTags()) {
      return Cache::tags([$tag])->remember($key, $ttl, $callback);
    }
    return Cache::remember($key, $ttl, $callback);
  }

  /**
  * Hapus satu item cache
  */
  public function forgetCache(string $suffix): void
  {
    $key = $this->cacheKey($suffix);
    $tag = $this->getCacheTag();

    if ($tag && $this->useCacheTags()) {
      // Karena tidak bisa hapus per item dalam tag, lebih baik flush seluruh tag
      Cache::tags([$tag])->flush();
    } else {
      Cache::forget($key);
    }
  }

  /**
  * Hapus seluruh cache dalam tag
  */
  public function flushTagCache(): void
  {
    $tag = $this->getCacheTag();
    if ($tag && $this->useCacheTags()) {
      Cache::tags([$tag])->flush();
    }
  }

  // ==================== PER-USER CACHE ====================

  /**
  * Dapatkan tag untuk user (override di class)
  */
  protected function getUserCacheTag(int $userId): string
  {
    return "user_{$userId}";
  }

  protected function getUserCachePrefix(int $userId): string
  {
    return "user_{$userId}";
  }

  protected function userCacheKey(int $userId, string $suffix): string
  {
    return $this->getUserCachePrefix($userId) . '_' . $suffix;
  }

  /**
  * Ambil atau simpan cache per user
  */
  public function rememberForUser(int $userId, string $suffix, int $ttl, callable $callback): mixed
  {
    $key = $this->userCacheKey($userId, $suffix);
    $tag = $this->getUserCacheTag($userId);

    if ($this->useCacheTags()) {
      return Cache::tags([$tag])->remember($key, $ttl, $callback);
    }
    return Cache::remember($key, $ttl, $callback);
  }

  /**
  * Hapus cache per user (seluruhnya)
  */
  public function clearUserCache(int $userId): void
  {
    $tag = $this->getUserCacheTag($userId);
    if ($this->useCacheTags()) {
      Cache::tags([$tag])->flush();
      return;
    }

    // Fallback untuk driver yang tidak support tag (Redis, file)
    if (config('cache.default') === 'redis') {
      $this->clearRedisUserCache($userId);
    } else {
      // Tidak ada cara mudah, biarkan saja atau implementasi manual
      // Bisa dengan menyimpan daftar key yang diketahui
    }
  }

  /**
  * Hapus satu item cache per user
  */
  public function forgetUserCacheItem(int $userId, string $suffix): void
  {
    $key = $this->userCacheKey($userId, $suffix);
    Cache::forget($key);
  }

  /**
  * Clear Redis cache dengan pattern
  */
  protected function clearRedisUserCache(int $userId): void
  {
    $redis = Cache::store('redis')->getStore()->connection();
    $prefix = config('database.redis.options.prefix', '') . 'cache:' . $this->getUserCachePrefix($userId);
    $cursor = null;
    do {
      [$cursor,
        $keys] = $redis->scan($cursor, ['match' => $prefix . '*', 'count' => 200]);
      if (!empty($keys)) {
        $unprefixed = array_map(fn($k) => str_replace(config('database.redis.options.prefix', ''), '', $k), $keys);
        $redis->del($unprefixed);
      }
    } while ($cursor);
  }
}