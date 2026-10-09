<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Style\Palette;
use Thallo\Contracts\Style\PaletteProvider;

/**
 * The palette one request sees (custom palette spec §3.2, §5.1): taken once, on first use, and
 * shared by the page cache's fingerprint, themeColorsStyle() and every class decision, so a page
 * and the key it is cached under always come from one reading. An Appearance preview overrides it
 * for its own render only; refresh() starts a new request's view.
 */
final class RequestPalette
{
    private ?Palette $palette = null;
    private ?Palette $override = null;

    public function __construct(private readonly ?PaletteProvider $provider = null)
    {
    }

    public function current(): Palette
    {
        return $this->override ?? ($this->palette ??= $this->provider?->palette() ?? Palette::empty());
    }

    /** A preview's unsaved palette for this render; null returns to the saved one. */
    public function override(?Palette $palette): void
    {
        $this->override = $palette;
    }

    public function refresh(): void
    {
        $this->palette = null;
        $this->override = null;
    }
}
