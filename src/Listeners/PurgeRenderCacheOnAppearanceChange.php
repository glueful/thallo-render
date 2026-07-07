<?php

declare(strict_types=1);

namespace Thallo\Render\Listeners;

use Glueful\Cache\CacheStore;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;

/**
 * ThemeAppearanceChanged → invalidateTags(['thallo:render:page']) — color config
 * touches every page (theme-color-config spec §7). Cache keys carry the appearance
 * fingerprint, so stale entries were never servable under the new pair; the purge
 * is hygiene for the old pair's keys. Services resolved per-invocation (listener
 * precedent).
 */
final class PurgeRenderCacheOnAppearanceChange
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onAppearanceChanged(object $event): void
    {
        if (!$event instanceof ThemeAppearanceChanged) {
            return;
        }
        $this->container->get(CacheStore::class)->invalidateTags(['thallo:render:page']);
    }
}
