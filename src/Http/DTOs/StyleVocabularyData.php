<?php

declare(strict_types=1);

namespace Thallo\Render\Http\DTOs;

use Glueful\Http\Contracts\ResponseData;

/** The platform vocabulary and the active theme's mapping of it (spec §2.1–2.2). Doc-only. */
final class StyleVocabularyData implements ResponseData
{
    public function __construct(
        public readonly int $version,
        /** Domain => ordinal names, e.g. `spacing` => `[none, xs, …]`. */
        public readonly object $domains,
        /** Token name => the active theme's CSS value, e.g. `spacing.lg` => `var(--space-4)`. */
        public readonly object $values,
    ) {
    }
}
