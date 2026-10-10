<?php

declare(strict_types=1);

namespace Thallo\Render\Http\DTOs;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder for the style schema's `palette` (custom palette spec §5.2): each brand
 * slot's state, a light swatch per colour name, every colour name's label, the palette generation
 * and the recent replacement records (§5.3). Never constructed at
 * runtime.
 */
final class StylePaletteData implements ResponseData
{
    public function __construct(
        /**
         * @var array<string, array<string,mixed>> `brand-N` => {name, hex, state: unset|configured|replacing,
         *      reserved, replacing: null|{to, to_label, contrast_to, contrast_to_label}}
         */
        public readonly array $slots,
        /** @var array<string,string> `color.<name>` => light-mode hex (no transparent, no brand slots) */
        public readonly array $swatches,
        /** @var array<string,string> `color.<name>` => label (brand slots: the author's name) */
        public readonly array $labels,
        /** @var int the palette generation the slots were read at */
        public readonly int $generation,
        /**
         * @var array<string,mixed> {after, through, records: list<{id, slot, map, completed_generation}>} —
         *      every completed replacement in the range, through `generation`
         */
        public readonly array $replacements,
    ) {
    }
}
