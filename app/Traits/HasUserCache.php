<?php

namespace Modules\FinTech\Traits;

use Illuminate\Support\Facades\Cache;

trait HasUserCache
{
  // ─── Prefix & Tag ─────────────────────────────

  protected function userCachePrefix(int $userId): string
  {
    return "user_{$userId}_";
  }

  protected function userCacheTag(int $userId): string
  {
    return "user_{$userId}";
  }

  protected function userCacheKey(int $userId, string $suffix): string
  {
    return $this->userCachePrefix($userId) . $suffix;
  }

  // ─── Simpan dengan tag (fallback tanpa tag) ──

  public function rememberForUser(int $userId, string $suffix, int $ttl, callable $callback): mixed
  {
    $key = $this->userCacheKey($userId, $suffix);

    if ($this->supportsTags()) {
      return Cache::tags([$this->userCacheTag($userId)])->remember($key, $ttl, $callback);
    }
    return Cache::remember($key, $ttl, $callback);
  }

  // ─── Pembersihan Cache ──────────────────────

  public function clearUserCache(int $userId): void
  {
    if ($this->supportsTags()) {
      Cache::tags([$this->userCacheTag($userId)])->flush();
      return;
    }

    // Fallback untuk Redis (SCAN) atau file/database (known keys)
    if ($this->isRedis()) {
      $this->clearRedisUserCache($userId);
    } else {
      $this->deleteKnownUserCacheKeys($userId);
    }
  }

  public function forgetUserCacheItem(int $userId, string $suffix): void
  {
    Cache::forget($this->userCacheKey($userId, $suffix));
  }

  // ─── Helpers (bisa diganti oleh service) ─────

  protected function supportsTags(): bool
  {
    return Cache::getStore() instanceof \Illuminate\Cache\TaggableStore;
  }

  protected function isRedis(): bool
  {
    return config('cache.default') === 'redis';
  }

  protected function knownUserCacheSuffixes(int $userId): array
  {
    return []; // timpa di service
  }

  protected function deleteKnownUserCacheKeys(int $userId): void
  {
    $keys = array_map(
      fn($suffix) => $this->userCacheKey($userId, $suffix),
      $this->knownUserCacheSuffixes($userId)
    );
    if (!empty($keys)) {
      Cache::deleteMultiple($keys);
    }
  }

  protected function clearRedisUserCache(int $userId): void
  {
    $redis = Cache::store('redis')->getStore()->connection();
    $cursor = null;
    $prefix = config('database.redis.options.prefix', '') . 'cache:' . $this->userCachePrefix($userId);

    do {
      [$cursor,
        $keys] = $redis->scan($cursor, ['match' => $prefix . '*', 'count' => 200]);
      if (!empty($keys)) {
        $unprefixed = array_map(
          fn($key) => str_replace(config('database.redis.options.prefix', ''), '', $key),
          $keys
        );
        $redis->del($unprefixed);
      }
    } while ($cursor);
  }
}