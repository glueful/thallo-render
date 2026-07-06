<?php

declare(strict_types=1);

namespace Thallo\Render;

/**
 * Paths the catch-all must never render. Prefixes use PATH-SEGMENT semantics ('v1'
 * reserves /v1 and /v1/... but NOT /v1abc); exacts match the whole path ('sitemap.xml'
 * does not reserve /sitemap-history). Reserved hits get the framework's standard JSON
 * 404 so API clients never receive themed HTML.
 */
final class ReservedPaths
{
    /**
     * @param list<string> $prefixes
     * @param list<string> $exact
     */
    public function __construct(
        private readonly array $prefixes,
        private readonly array $exact,
    ) {
    }

    public function isReserved(string $path): bool
    {
        $trimmed = trim($path, '/');
        if (in_array($trimmed, $this->exact, true)) {
            return true;
        }
        $first = explode('/', $trimmed, 2)[0];
        return in_array($first, $this->prefixes, true);
    }
}
