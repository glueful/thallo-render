<?php

declare(strict_types=1);

namespace Thallo\Render;

use Glueful\Cache\CacheStore;
use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Tenancy\Cache\TenantCacheSegment;

/**
 * The fixed single-body 404/410 cache (spec §2 amendment). Consulted by the controller
 * BEFORE rendering 404.twig / error.twig: a warm key serves the stored body without
 * touching Twig — this, not per-path storage, is what kills render amplification for
 * bogus URLs (the per-path middleware only sees a 404 after the render already ran).
 *
 * ONE body per theme per status (render:{theme}:404 / render:{theme}:410), tagged
 * thallo:render:page and emitted as a Cache-Tag header so server AND CDN purges compose.
 * Only responses that match the expected status and are text/html are stored — a
 * failed error render (plain-text 500 fallback) is never cached. Same CacheStore
 * binding as the rest of the render cache (spec §3 pin).
 */
final class RenderErrorCache
{
    public function __construct(
        private readonly CacheStore $cache,
        private readonly string $theme,
        /** Validated accent-neutral fingerprint (theme-color-config spec §7). */
        private readonly string $appearance,
        private readonly bool $enabled,
        private readonly int $ttl,
        private readonly ?TenantCacheSegment $tenantCache = null,
        private readonly ?ApplicationContext $context = null,
    ) {
    }

    /** Fixed error-body key, appearance-scoped so a color switch can't serve stale chrome. */
    private function key(int $status): string
    {
        $prefix = $this->tenantCache !== null && $this->context !== null
            ? $this->tenantCache->segment($this->context, 'render')
            : '';

        return $prefix . "render:{$this->theme}:{$this->appearance}:{$status}";
    }

    /** @param callable(): Response $render renders 404.twig — invoked only on a cold key */
    public function themed404(callable $render): Response
    {
        return $this->fixedError(404, $render);
    }

    /** @param callable(): Response $render renders error.twig at 410 — invoked only on a cold key */
    public function themed410(callable $render): Response
    {
        return $this->fixedError(410, $render);
    }

    /** @param callable(): Response $render */
    private function fixedError(int $status, callable $render): Response
    {
        if (!$this->enabled) {
            return $render();
        }

        $key = $this->key($status);
        $stored = $this->cache->get($key);
        if (is_array($stored)) {
            return new Response((string) $stored['body'], $status, [
                'Content-Type' => (string) $stored['contentType'],
                'Cache-Tag' => 'thallo:render:page',
            ]);
        }

        $response = $render();
        $contentType = (string) $response->headers->get('Content-Type');
        if ($response->getStatusCode() !== $status || !str_contains($contentType, 'text/html')) {
            return $response; // e.g. the error template itself failed → 500: never store.
        }

        $this->cache->set(
            $key,
            ['body' => (string) $response->getContent(), 'contentType' => $contentType],
            $this->ttl,
        );
        $this->cache->addTags($key, ['thallo:render:page']);
        $response->headers->set('Cache-Tag', 'thallo:render:page');
        return $response;
    }
}
