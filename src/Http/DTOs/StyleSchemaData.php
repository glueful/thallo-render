<?php

declare(strict_types=1);

namespace Thallo\Render\Http\DTOs;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder for `GET /v1/admin/render/style-schema` (visual builder spec §1.3,
 * §2.1): the property table the inspector's controls are generated from, the breakpoints, the
 * advanced paths, and the active theme's vocabulary. Never constructed at runtime.
 */
final class StyleSchemaData implements ResponseData
{
    public function __construct(
        /** `StyleSchema::VERSION` — the settings schema the table describes. */
        public readonly int $version,
        /** Breakpoint name => minimum width in CSS pixels, in cascade order. */
        public readonly object $breakpoints,
        /** @var list<StylePropertyData> */
        public readonly array $properties,
        /** @var list<string> The `settings.advanced` paths. */
        public readonly array $advanced,
        public readonly StyleVocabularyData $vocabulary,
    ) {
    }
}
