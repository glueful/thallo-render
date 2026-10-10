<?php

declare(strict_types=1);

namespace Thallo\Render\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Thallo\Contracts\Style\Palette;
use Thallo\Contracts\Style\PaletteHistoryReader;
use Thallo\Contracts\Style\PaletteStatusReader;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Contracts\Style\ValueKind;
use Thallo\Contracts\Style\Vocabulary;
use Thallo\Render\Http\DTOs\StyleSchemaData;
use Thallo\Render\Style\RequestPalette;
use Thallo\Render\Theme\EffectivePalette;
use Thallo\Render\Theme\ThemeColors;
use Thallo\Render\ThemeAppearanceSource;
use Thallo\Render\ThemeLocator;

/**
 * The style schema endpoint (visual builder spec §1.3, §3.4): the one runtime source the
 * inspector generates its controls from — the property table with kinds, responsiveness and
 * choices, the breakpoints, the advanced paths, and the active theme's vocabulary so a token
 * control can preview the theme's value. OpenAPI types stay compile-time only.
 */
final class StyleSchemaController
{
    /** The colour names' labels (custom palette spec §5.2); brand slots take their authors' names. */
    public const LABELS = [
        'background' => 'Background', 'surface' => 'Surface', 'surface-2' => 'Surface 2', 'text' => 'Text',
        'muted' => 'Muted', 'line' => 'Line', 'accent' => 'Accent', 'accent-contrast' => 'Accent — text',
        'transparent' => 'Transparent', 'white' => 'White', 'black' => 'Black',
    ];

    /** Until the palette block lists the configured ids in order (brand colour list plan Task 6). */
    private const INTERIM_SLOTS = [1, 2, 3];

    public function __construct(
        private readonly ThemeLocator $theme,
        private readonly ?RequestPalette $palette = null,
        private readonly ?ThemeAppearanceSource $appearance = null,
        private readonly ?PaletteStatusReader $statuses = null,
        private readonly ?PaletteHistoryReader $history = null,
        /** Whether the site renders a dark mode (`theme.color_mode.enabled`): the dark base matters only then. */
        private readonly bool $colorMode = true,
    ) {
    }

    #[ApiOperation(
        summary: 'The style schema and the active theme vocabulary',
        description: 'The managed property table (paths, kinds, responsiveness, choices), the breakpoints, '
            . 'the advanced paths, the active theme\'s vocabulary values and the workspace\'s palette (brand slot '
            . 'states, swatches, labels). Any style editor may read it: `content.edit`, `content.manage`, '
            . '`templates.manage` or `styles.manage`.',
        tags: ['Thallo Templates'],
    )]
    #[ApiResponse(200, schema: StyleSchemaData::class, description: 'The style schema.')]
    public function show(): Response
    {
        $properties = [];
        foreach (StyleSchema::properties() as $def) {
            $properties[] = [
                'path' => $def->path,
                'group' => $def->group,
                'kinds' => array_map(static fn (ValueKind $kind): string => $kind->value, $def->kinds),
                'responsive' => $def->responsive,
                'token_domain' => $def->tokenDomain,
                'choices' => $def->choices,
            ];
        }
        $domains = [];
        foreach (Vocabulary::domains() as $domain) {
            $domains[$domain] = Vocabulary::names($domain);
        }
        return Response::success([
            'version' => StyleSchema::VERSION,
            'breakpoints' => StyleSchema::BREAKPOINT_MIN_WIDTH,
            'properties' => $properties,
            'advanced' => array_keys(StyleSchema::advanced()),
            'vocabulary' => [
                'version' => Vocabulary::VERSION,
                'domains' => $domains,
                'values' => $this->theme->vocabulary()->values(),
            ],
            'palette' => $this->consistentPalette(),
        ], 'Style schema retrieved.');
    }

    /**
     * The schema's palette block as it stands now — what a palette change answers with, so the
     * Appearance page and the pickers need no second request.
     *
     * @return array<string,mixed>
     */
    public function paletteBlock(): array
    {
        return $this->consistentPalette();
    }

    /**
     * The palette with its generation and the recent replacement records (custom palette spec §5.3),
     * all read at one moment: a replacement completing mid-read makes the slots be read again.
     *
     * @return array<string,mixed>
     */
    private function consistentPalette(): array
    {
        if ($this->history === null) {
            return $this->palette() + [
                'generation' => 0,
                'replacements' => ['after' => 0, 'through' => 0, 'records' => []],
            ];
        }
        [$palette, $generation, $replacements] = $this->history->read(function (): array {
            $this->palette?->refresh();
            return $this->palette();
        });
        return $palette + ['generation' => $generation, 'replacements' => $replacements];
    }

    /**
     * The workspace's palette for the pickers (custom palette spec §5.2): each brand slot's state
     * (unset, configured or replacing), whether a running replacement reserves it and where a slot
     * being replaced is going; a light swatch for every other colour name; and every colour name's
     * label.
     *
     * @return array{
     *     slots: array<string, array<string,mixed>>,
     *     swatches: array<string,string>,
     *     labels: array<string,string>,
     * }
     */
    private function palette(): array
    {
        $palette = $this->palette?->current() ?? Palette::empty();
        $statuses = $this->statuses?->statuses() ?? [];
        $labels = [];
        foreach (self::LABELS as $name => $label) {
            $labels['color.' . $name] = $label;
        }
        foreach (self::INTERIM_SLOTS as $slot) {
            $name = $palette->brand($slot)?->name ?? "Brand {$slot}";
            $labels["color.brand-{$slot}"] = $name;
            $labels["color.brand-{$slot}-contrast"] = $name . ' — text';
        }
        $slots = [];
        foreach (self::INTERIM_SLOTS as $slot) {
            $brand = $palette->brand($slot);
            $replacing = $statuses[$slot]['replacing'] ?? null;
            $slots["brand-{$slot}"] = [
                'name' => $brand?->name,
                'hex' => $brand?->hex,
                'state' => $brand === null ? 'unset' : ($replacing !== null ? 'replacing' : 'configured'),
                'reserved' => (bool) ($statuses[$slot]['reserved'] ?? false),
                'replacing' => $replacing === null ? null : [
                    'to' => $replacing['to'],
                    'to_label' => $labels[$replacing['to']] ?? $replacing['to'],
                    'contrast_to' => $replacing['contrast_to'],
                    'contrast_to_label' => $replacing['contrast_to'] === null
                        ? null
                        : ($labels[$replacing['contrast_to']] ?? $replacing['contrast_to']),
                ],
            ];
        }
        $swatches = EffectivePalette::of(
            $this->appearance?->accent() ?? ThemeColors::DEFAULT_ACCENT,
            $this->appearance?->neutral() ?? ThemeColors::DEFAULT_NEUTRAL,
            $this->appearance?->background() ?? 'plain',
            $palette,
        )->swatches();
        return ['slots' => $slots, 'swatches' => $swatches, 'labels' => $labels, 'color_mode' => $this->colorMode];
    }
}
