<?php

declare(strict_types=1);

namespace Thallo\Render\Http;

/**
 * Doc-only request-body shape for PUT /v1/admin/render/templates/{path} (reflected by
 * the OpenAPI generator via #[ApiRequestBody]; never hydrated at runtime — the
 * controller validates the raw JSON itself so lint errors can carry line numbers).
 */
final class TemplateSaveBody
{
    public function __construct(
        public readonly string $source,
    ) {
    }
}
