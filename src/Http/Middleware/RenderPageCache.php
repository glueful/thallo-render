<?php

declare(strict_types=1);

namespace Thallo\Render\Http\Middleware;

use Glueful\Cache\CacheStore;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Tenancy\Cache\TenantCacheSegment;

/**
 * Full-page cache for the rendered site (render caching spec §2–§3, §5).
 *
 * PER-PATH entries store ONLY 200 responses whose Content-Type is text/html (a
 * content render), keyed render:{theme}:{normalizedPath}. The themed 404/410 bodies
 * are NOT stored here — RenderErrorCache holds them under fixed keys and is consulted
 * by the controller BEFORE Twig renders (spec §2 amendment: a middleware sees a 404
 * only after the render already happened). This middleware still applies the uniform
 * HTTP validators (ETag / If-None-Match 304 / Cache-Control) to 404/410 text/html
 * responses so hit and miss carry identical semantics. Reserved-path JSON 404s,
 * redirects, 500s, and any non-HTML response pass through untouched.
 *
 * Storage goes through the SAME CacheStore binding InvalidateCacheTagsListener
 * invalidates. Every cached 200 is tagged with the surrogate keys the controller
 * emits in Cache-Tag (thallo:entry:{uuid}, thallo:type:{slug}) plus thallo:render:page.
 * On a non-tag driver addTags() is a no-op and freshness degrades to the TTL window
 * (spec §3 fallback) — nothing breaks.
 */
final class RenderPageCache implements RouteMiddleware
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

    public function handle(Request $request, callable $next, ...$params): mixed
    {
        // Verified preview sessions bypass the page cache wholesale (preview-sessions
        // spec §4): no read, no store. Verification happened in
        // PreviewSessionMiddleware — this layer only honors the attribute and never
        // parses cookies itself.
        if ($request->attributes->has(PreviewSessionMiddleware::ATTRIBUTE)) {
            return $next($request);
        }

        if (!$this->enabled) {
            return $next($request);
        }

        $key = $this->key($request->getPathInfo());
        $hit = $this->cache->get($key);
        if (is_array($hit)) {
            return $this->respond($request, $hit);
        }

        $response = $next($request);
        if (!$response instanceof Response) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response; // eligibility pin: JSON reserved 404s / redirects / non-HTML.
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getContent();
        $cacheTag = (string) $response->headers->get('Cache-Tag', '');

        if ($status === 200) {
            $entry = $this->entry($body, 200, $contentType, $cacheTag);
            $this->cache->set($key, $entry, $this->ttl);
            $this->cache->addTags($key, [...$this->surrogateTags($cacheTag), 'thallo:render:page']);
            // Serve stored entries on the miss path too, so hit and miss responses
            // carry identical headers (ETag / Cache-Control).
            return $this->respond($request, $entry);
        }

        if ($status === 404 || $status === 410) {
            // Body already comes from RenderErrorCache's fixed key (or its first
            // render) — no storage here, just the uniform validators.
            return $this->respond($request, $this->entry($body, $status, $contentType, $cacheTag));
        }

        return $response; // 500s: never cached, untouched.
    }

    /**
     * render:{theme}:{rawurlencode(normalizedPath)} — duplicate slashes collapsed,
     * trailing slash trimmed (root stays '/'), mirroring the resolver's canonical
     * rules. The path is rawurlencoded because the framework's Redis driver rejects
     * PSR-16-reserved characters ({}()/\@) in keys — a raw '/' 500s every live
     * render on Redis. The encoded alphabet (alnum, -_.~, %XX) contains none of
     * them and is reversible, so keys stay collision-free; every per-path key
     * starts "render:{theme}:%2F", disjoint from the fixed render:{theme}:404 /
     * render:{theme}:410 error keys by construction.
     */
    private function key(string $path): string
    {
        $prefix = $this->tenantCache !== null && $this->context !== null
            ? $this->tenantCache->segment($this->context, 'render')
            : '';

        return $prefix . "render:{$this->theme}:{$this->appearance}:"
            . rawurlencode(self::normalizePath($path));
    }

    /**
     * The ONE request-path normalizer (nav-v2 spec §3): cache keys and the
     * render context's current_path must never disagree on what "the path" is.
     *
     * SCOPE PIN (nav-v2 review P2): HTTP-path hygiene ONLY — strips any query
     * string, collapses duplicate slashes, trims the trailing slash (root
     * stays '/'). It performs NO canonical routing decisions: no locale
     * collapse, no root-mount conversion, no redirect logic. Those happen
     * BEFORE render (301s + CanonicalPathBuilder). This is not a canonical
     * URL builder — do not grow it into one.
     */
    public static function normalizePath(string $path): string
    {
        $path = (string) strtok($path, '?');
        $collapsed = (string) preg_replace('#/{2,}#', '/', '/' . trim($path, " \t"));
        $trimmed = rtrim($collapsed, '/');
        return $trimmed === '' ? '/' : $trimmed;
    }

    /** @param array{body: string, status: int, contentType: string, cacheTag: string, etag: string} $entry */
    private function respond(Request $request, array $entry): Response
    {
        $headers = [
            'Content-Type' => $entry['contentType'],
            'ETag' => $entry['etag'],
            'Cache-Control' => 'public, max-age=0, must-revalidate',
        ];
        if ($entry['cacheTag'] !== '') {
            $headers['Cache-Tag'] = $entry['cacheTag'];
        }
        if ($this->etagMatches($request, $entry['etag'])) {
            return new Response('', 304, $headers);
        }
        return new Response($entry['body'], $entry['status'], $headers);
    }

    /** @return array{body: string, status: int, contentType: string, cacheTag: string, etag: string} */
    private function entry(string $body, int $status, string $contentType, string $cacheTag): array
    {
        return [
            'body' => $body,
            'status' => $status,
            'contentType' => $contentType,
            'cacheTag' => $cacheTag,
            'etag' => '"' . sha1($body) . '"',
        ];
    }

    /** @return list<string> the surrogate keys from a Cache-Tag header value */
    private function surrogateTags(string $cacheTag): array
    {
        if ($cacheTag === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $cacheTag))));
    }

    private function etagMatches(Request $request, string $etag): bool
    {
        $ifNoneMatch = (string) $request->headers->get('If-None-Match', '');
        if ($ifNoneMatch === '') {
            return false;
        }
        foreach (array_map('trim', explode(',', $ifNoneMatch)) as $candidate) {
            if ($candidate === $etag || $candidate === 'W/' . $etag || $candidate === '*') {
                return true;
            }
        }
        return false;
    }
}
