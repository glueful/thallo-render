<?php

declare(strict_types=1);

namespace Thallo\Render\Http\DTOs;

use Glueful\Http\Contracts\ResponseData;

/** One row of the style property table (spec §1.3). Doc-only. */
final class StylePropertyData implements ResponseData
{
    public function __construct(
        public readonly string $path,
        public readonly string $group,
        /** @var list<string> The value kinds accepted: `token`, `choice`, `identifier`, `reset`. */
        public readonly array $kinds,
        public readonly bool $responsive,
        /** The vocabulary domain a token value comes from; null for a choice property. */
        public readonly ?string $token_domain,
        /** @var list<string>|null The closed set of choices; null for a token property. */
        public readonly ?array $choices,
    ) {
    }
}
