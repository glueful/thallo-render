<?php

declare(strict_types=1);

namespace Thallo\Render\Http\DTOs;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder for `GET /v1/admin/render/appearance-fingerprint` (block typeface plan
 * Task 11): what a stage's head depends on, for an open stage to tell when to reload. Never
 * constructed at runtime.
 */
final class AppearanceFingerprintData implements ResponseData
{
    public function __construct(
        /** The theme, the saved appearance and the fonts stylesheet, joined; opaque. */
        public readonly string $appearance_fingerprint,
    ) {
    }
}
