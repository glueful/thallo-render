<?php

declare(strict_types=1);

namespace Thallo\Render\Listeners;

use Glueful\Cache\CacheStore;
use Psr\Container\ContainerInterface;

/**
 * MenuUpdated → invalidateTags(['lemma:render:page']) (spec §4): menus can appear on
 * any rendered page, so menu mutations purge every cached page including the fixed
 * 404/410 bodies (they render the nav too). The CacheStore is resolved per-invocation,
 * not captured at construction — same rationale as the engine's
 * InvalidateCacheTagsListener (long-lived singleton, current cache.store binding).
 */
final class PurgeRenderCacheOnMenuUpdate
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onMenuUpdated(object $event): void
    {
        $this->container->get(CacheStore::class)->invalidateTags(['lemma:render:page']);
    }
}
