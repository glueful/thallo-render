<?php

declare(strict_types=1);

namespace Thallo\Render\Listeners;

use Psr\Container\ContainerInterface;
use Thallo\Contracts\Delivery\RenderedPageCachePurge;
use Thallo\Contracts\Style\StyleClassSaved;

/**
 * StyleClassSaved → purge(['thallo:render:page']) — a class can reach any page (visual builder
 * spec §4.3). Keys carry the style generation, so stale entries were never servable under the
 * new generation; the purge is hygiene for the previous generation's keys. Services resolved
 * per-invocation (listener precedent).
 */
final class PurgeRenderCacheOnStyleClassChange
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function onStyleClassSaved(object $event): void
    {
        if (!$event instanceof StyleClassSaved) {
            return;
        }
        $this->container->get(RenderedPageCachePurge::class)->purge(['thallo:render:page']);
    }
}
