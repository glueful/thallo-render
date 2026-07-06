<?php

declare(strict_types=1);

namespace Thallo\Render\Listeners;

use Glueful\Cache\CacheStore;
use Psr\Container\ContainerInterface;

/**
 * RegionUpdated → invalidateTags(['lemma:render:page']) (global-regions spec §11):
 * chrome regions appear on EVERY rendered page, so a region save purges every
 * cached page including the fixed 404/410 bodies (they render the chrome too).
 * Broad purge over cleverness. The CacheStore is resolved per-invocation, not
 * captured at construction — same rationale as PurgeRenderCacheOnMenuUpdate.
 */
final class PurgeRenderCacheOnRegionUpdate
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onRegionUpdated(object $event): void
    {
        $this->container->get(CacheStore::class)->invalidateTags(['lemma:render:page']);
    }
}
