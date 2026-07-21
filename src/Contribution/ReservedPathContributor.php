<?php

declare(strict_types=1);

namespace Thallo\Render\Contribution;

/**
 * Contributes paths {@see \Thallo\Render\ReservedPaths} must treat as reserved (storefront-
 * rendering spec §5.1) — e.g. thallo-commerce's `{prefix}`, `cart`, `checkout`, `_shop`.
 * Register with {@see RenderContributionRegistry::registerReservedPaths()} during provider
 * boot(); consumed once, at the registry's first frozen read, by
 * {@see \Thallo\Render\RenderServiceProvider::makeReservedPaths()}.
 */
interface ReservedPathContributor
{
    /** Unique across every reserved-path contributor — duplicates are rejected at registration. */
    public function contributorId(): string;

    /** Ordering key when multiple contributors register: sorted by (priority, contributorId). */
    public function priority(): int;

    /** @return list<string> path-segment prefixes (see ReservedPaths for PATH-SEGMENT semantics) */
    public function reservedPrefixes(): array;

    /** @return list<string> whole-path exact matches */
    public function reservedExacts(): array;
}
