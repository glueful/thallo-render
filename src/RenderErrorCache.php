<?php

declare(strict_types=1);

namespace Thallo\Render;

use Glueful\Cache\CacheStore;
use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Render\Cache\RenderCacheGuards;
use Thallo\Render\Cache\RenderCacheHints;
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
 *
 * The body's chrome can hold a Product grid (a header or footer region), so it is cached like a
 * page (product grid spec §3.2): with the render's private storage tags and its guards, which are
 * re-checked on every hit; a render that may not be cached is not stored.
 */
final class RenderErrorCache
{
    public function __construct(
        private readonly CacheStore $cache,
        private readonly string $theme,
        /** The appearance fingerprint (theme-color-config spec §7), evaluated per request so the key
         *  names the style class snapshot the same request renders from (visual builder spec §4.3). */
        private readonly \Closure $appearance,
        private readonly bool $enabled,
        private readonly int $ttl,
        private readonly ?TenantCacheSegment $tenantCache = null,
        private readonly ?ApplicationContext $context = null,
        /** The cold render's cache hints, drained from the render extension after it ran. */
        private readonly ?\Closure $hints = null,
    ) {
    }

    /** Fixed error-body key, appearance-scoped so a color switch can't serve stale chrome. */
    private function key(int $status): string
    {
        $prefix = $this->tenantCache !== null && $this->context !== null
            ? $this->tenantCache->segment($this->context, 'render')
            : '';

        $appearance = ($this->appearance)();
        return $prefix . "render:{$this->theme}:{$appearance}:{$status}";
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
        if (is_array($stored) && !RenderCacheGuards::hold($this->cache, $stored['guards'] ?? [])) {
            $this->cache->delete($key);
            $stored = null;
        }
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

        $hints = $this->hints !== null ? ($this->hints)() : new RenderCacheHints();
        if ($hints->uncacheable || !RenderCacheGuards::hold($this->cache, $hints->guards)) {
            return $response;
        }
        $this->cache->set(
            $key,
            ['body' => (string) $response->getContent(), 'contentType' => $contentType, 'guards' => $hints->guards],
            $this->ttl,
        );
        $this->cache->addTags($key, [...$hints->storageTags, 'thallo:render:page']);
        $response->headers->set('Cache-Tag', 'thallo:render:page');
        return $response;
    }
}
