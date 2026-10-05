<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Fonts\FontLibraryReader;
use Thallo\Contracts\Fonts\FontLibrarySnapshotView;

/**
 * The font library as one request sees it (block typeface spec §3.4): taken once, on first use, and
 * shared by everything that request decides about typefaces — the page cache's fingerprint (computed
 * before the render), each block's utility, the fonts stylesheet and its link. A render's own reset
 * does not drop it, so the fingerprint and the page it keys always come from one snapshot; refresh()
 * starts a new request's view (as the style class provider's snapshot does).
 */
final class RequestFontSnapshot
{
    private ?FontLibrarySnapshotView $snapshot = null;
    private bool $taken = false;

    public function __construct(private readonly ?FontLibraryReader $library = null)
    {
    }

    /** Null when no font library is bound: only the built-in typefaces resolve. */
    public function current(): ?FontLibrarySnapshotView
    {
        if (!$this->taken) {
            $this->snapshot = $this->library?->snapshot();
            $this->taken = true;
        }
        return $this->snapshot;
    }

    public function refresh(): void
    {
        $this->snapshot = null;
        $this->taken = false;
    }
}
