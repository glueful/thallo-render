<?php

declare(strict_types=1);

namespace Thallo\Render\Listeners;

use Glueful\Cache\CacheStore;
use Thallo\Contracts\Settings\ThemeChanged;
use Psr\Container\ContainerInterface;

/**
 * ThemeChanged → invalidateTags(['thallo:render:page']) — the theme touches
 * EVERY page and the themed 404/410 error bodies, so the purge is the same
 * broad tag the region/menu/template listeners use (theme-setting spec §5).
 * Cache keys are theme-scoped (render:{theme}:…) so stale entries were never
 * servable to the NEW theme; the purge is hygiene for the old theme's keys
 * and the error bodies. Services resolved per-invocation (listener precedent).
 */
final class PurgeRenderCacheOnThemeChange
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onThemeChanged(object $event): void
    {
        if (!$event instanceof ThemeChanged) {
            return;
        }
        $this->container->get(CacheStore::class)->invalidateTags(['thallo:render:page']);
    }
}
