<?php

declare(strict_types=1);

namespace Thallo\Render\Listeners;

use Glueful\Cache\CacheStore;
use Thallo\Render\Templates\TemplateUpdated;
use Thallo\Render\ThemeLocator;
use Psr\Container\ContainerInterface;

/**
 * TemplateUpdated → invalidateTags(['thallo:render:page']) — ONLY when the edited theme
 * is the ACTIVE theme (spec §5): inactive themes never populate the shared caches
 * (preview sessions are uncached). The one tag covers the page cache AND the fixed
 * 404/410 bodies (RenderErrorCache tags them identically). Broad purge over cleverness
 * — a template affects an unknowable page set. Compiled Twig cache: untouched
 * (version-keyed). Services resolved per-invocation (menu-listener precedent).
 */
final class PurgeRenderCacheOnTemplateUpdate
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onTemplateUpdated(object $event): void
    {
        if (!$event instanceof TemplateUpdated) {
            return;
        }
        $active = $this->container->get(ThemeLocator::class)->activePaths()['name'];
        if ($event->theme !== $active) {
            return;
        }
        $this->container->get(CacheStore::class)->invalidateTags(['thallo:render:page']);
    }
}
