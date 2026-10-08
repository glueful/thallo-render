<?php

declare(strict_types=1);

namespace Thallo\Render\Cache;

use Symfony\Component\HttpFoundation\Request;

/**
 * What a render tells the page caches beyond its HTML (product grid spec §3.2): private storage
 * tags (stored on the entry, never in the public Cache-Tag header), cache guards — cache keys and
 * the values the render read — and whether the render may be cached at all. Carried on the
 * request's attributes; never sent to the client.
 */
final class RenderCacheHints
{
    public const ATTRIBUTE = 'thallo.render_cache_hints';

    /**
     * @param list<string> $storageTags
     * @param array<string,string> $guards cache key => the value the render read
     */
    public function __construct(
        public readonly array $storageTags = [],
        public readonly array $guards = [],
        public readonly bool $uncacheable = false,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $hints = $request->attributes->get(self::ATTRIBUTE);
        return $hints instanceof self ? $hints : new self();
    }
}
