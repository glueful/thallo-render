<?php

declare(strict_types=1);

namespace Thallo\Render\Regions;

use Thallo\Contracts\Content\RegionReader;

/**
 * A regions-stage session's snapshot as a RegionReader (regions-stage spec §4.4): one render of the
 * stage reads both regions — blocks and settings — from this one captured record, never the live
 * rows. An empty region is an empty list, which the stage draws as an empty slot.
 */
final class RegionSessionReader implements RegionReader
{
    /** @param array<string, array<string,mixed>> $regions keyed by slug: {blocks, settings} */
    public function __construct(private readonly array $regions)
    {
    }

    public function blocks(string $slug): ?array
    {
        $blocks = $this->regions[$slug]['blocks'] ?? [];
        return is_array($blocks) ? array_values($blocks) : [];
    }

    public function settings(string $slug): array
    {
        $settings = $this->regions[$slug]['settings'] ?? [];
        return is_array($settings) ? $settings : [];
    }
}
