<?php

declare(strict_types=1);

namespace Thallo\Render\Http\DTOs;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder for the style schema's `palette` (custom palette spec §5.2): the deployment's
 * limit, the brand colours in the author's order with their states, removed colours by name, a light
 * swatch per colour name, every colour name's label, whether the reader may manage brand colours, the
 * palette generation and the recent replacement records (§5.3). Never constructed at runtime.
 */
final class StylePaletteData implements ResponseData
{
    public function __construct(
        /** @var int how many brand colours the deployment allows (`theme.brand_colors.max`; 0 = off) */
        public readonly int $limit,
        /** @var list<string> the configured and replacing brand colours (`brand-N`), in the author's order */
        public readonly array $order,
        /**
         * @var array<string, array<string,mixed>> `brand-N` => {name, hex, state: configured|replacing,
         *      reserved, replacing: null|{to, to_label, contrast_to, contrast_to_label}}, or a removed colour
         *      {name, state: removed}; a never-issued id is absent
         */
        public readonly array $slots,
        /** @var array<string,string> `color.<name>` => light-mode hex (no transparent, no brand slots) */
        public readonly array $swatches,
        /** @var array<string,string> `color.<name>` => label (brand colours: the author's name, removed ones too) */
        public readonly array $labels,
        /** @var bool whether the reader may manage brand colours (`content.manage`) */
        public readonly bool $can_manage,
        /** @var bool whether the site renders a dark mode: the dark base applies only then */
        public readonly bool $color_mode,
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
