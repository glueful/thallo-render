<?php

declare(strict_types=1);

namespace Thallo\Render\Cache;

use Glueful\Cache\CacheStore;

/** Whether every guard still reads the value it was stored with (product grid spec §3.2). */
final class RenderCacheGuards
{
    /** @param array<string,string> $guards */
    public static function hold(CacheStore $cache, array $guards): bool
    {
        foreach ($guards as $key => $value) {
            $now = $cache->get($key);
            if (!is_string($now) || $now !== $value) {
                return false;
            }
        }
        return true;
    }
}
