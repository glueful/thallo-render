<?php

declare(strict_types=1);

namespace Thallo\Render\Http\Middleware;

use Glueful\Cache\CacheStore;
use Thallo\Contracts\Delivery\RenderedPageCachePurge;

/**
 * The one purge path for rendered pages ({@see RenderPageCache}, {@see \Thallo\Render\RenderErrorCache}).
 * Tag invalidation when the driver supports it; otherwise the whole `render:*` namespace (and its
 * tenant-segmented twin), which is the `render:cache:clear` command's own sweep — a content
 * change must never wait out the TTL on the default file driver.
 */
final class RenderCachePurge implements RenderedPageCachePurge
{
    public function __construct(private readonly CacheStore $cache)
    {
    }

    public function purge(array $tags): void
    {
        if ($tags === [] || $this->cache->invalidateTags($tags)) {
            return;
        }
        $this->purgeAll();
    }

    public function purgeAll(): bool
    {
        $legacy = $this->cache->deletePattern('render:*');
        $segmented = $this->cache->deletePattern('tenant:*:render:*');
        return $legacy && $segmented;
    }
}
