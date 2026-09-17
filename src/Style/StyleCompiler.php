<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Style\StyleSchema;
use Thallo\Contracts\Style\ValueKind;
use Thallo\Contracts\Style\Vocabulary;

/**
 * Compiles a theme's vocabulary into the compiled style artifact (visual builder spec §2.4):
 * `@layer settings` holding the `--t-*` custom properties, one utility per managed property,
 * value and breakpoint, and one `revert-layer` utility per property and breakpoint, emitted
 * base, then md, then lg. Deterministic: a pure function of the vocabulary, the vocabulary
 * schema version and this class's VERSION, which together are the artifact's hash.
 */
final class StyleCompiler
{
    // 2: colors.surface compiles to the background shorthand (a theme gradient yields to it).
    public const VERSION = 3;

    private const MEDIA = ['md' => 768, 'lg' => 1024];

    private const CHOICE_DECLARATIONS = [
        'alignment.text' => ['text-align' => ['start' => 'start', 'center' => 'center', 'end' => 'end']],
        'alignment.content' => [
            'justify-content' => [
                'start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end',
                // Distribution keywords (container-layout spec §3.2): a container's inner area
                // spreads its children with the same property.
                'between' => 'space-between', 'around' => 'space-around', 'evenly' => 'space-evenly',
            ],
        ],
        'alignment.self' => ['margin-inline' => ['start' => '0 auto', 'center' => 'auto', 'end' => 'auto 0']],
        'typography.weight' => [
            'font-weight' => ['regular' => '400', 'medium' => '500', 'semibold' => '600', 'bold' => '700'],
        ],
        'visibility' => ['display' => ['visible' => 'revert-layer', 'hidden' => 'none']],
        'border.width' => ['border-width' => ['none' => '0', 'thin' => '1px', 'thick' => '2px']],
        'border.style' => ['border-style' => ['solid' => 'solid', 'dashed' => 'dashed']],
        // Layout (container-layout spec §3.2). `layout.columns`, `layout.min_height`,
        // `layout.content_width` and `layout.span` are compiled by hand below: their declarations
        // are not one property = one value.
        'layout.display' => ['display' => ['block' => 'block', 'flex' => 'flex', 'grid' => 'grid']],
        'layout.direction' => ['flex-direction' => [
            'row' => 'row', 'column' => 'column', 'row-reverse' => 'row-reverse',
            'column-reverse' => 'column-reverse',
        ]],
        'layout.wrap' => ['flex-wrap' => ['nowrap' => 'nowrap', 'wrap' => 'wrap']],
        'layout.align_items' => ['align-items' => [
            'start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end',
            'stretch' => 'stretch', 'baseline' => 'baseline',
        ]],
        'layout.overflow' => ['overflow' => [
            'visible' => 'visible', 'hidden' => 'hidden', 'auto' => 'auto',
        ]],
        'layout.basis' => ['flex-basis' => [
            'auto' => 'auto', '1/4' => '25%', '1/3' => '33.333%', '1/2' => '50%',
            '2/3' => '66.667%', '3/4' => '75%', 'full' => '100%',
        ]],
        'layout.grow' => ['flex-grow' => ['0' => '0', '1' => '1']],
        'layout.shrink' => ['flex-shrink' => ['0' => '0', '1' => '1']],
        'layout.align_self' => ['align-self' => [
            'start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end', 'stretch' => 'stretch',
        ]],
    ];

    /** Track presets: the class value => its `grid-template-columns` and its track count. */
    private const TRACKS = [
        '1' => 1, '2' => 2, '3' => 3, '4' => 4, '6' => 6, '12' => 12,
        '1-2' => 2, '2-1' => 2, '1-3' => 2, '3-1' => 2, '1-2-1' => 3, '1-1-2' => 3, '2-1-1' => 3,
    ];

    /** token properties: the CSS property that reads the token variable */
    private const TOKEN_PROPERTY = [
        'spacing.padding.top' => 'padding-top',
        'spacing.padding.right' => 'padding-right',
        'spacing.padding.bottom' => 'padding-bottom',
        'spacing.padding.left' => 'padding-left',
        'spacing.margin.top' => 'margin-top',
        'spacing.margin.bottom' => 'margin-bottom',
        'typography.size' => 'font-size',
        'shadow' => 'box-shadow',
        'radius' => 'border-radius',
        // The shorthand: the surface colour owns the whole background, so a theme's gradient
        // (a background-image) yields to a managed colour exactly like a flat theme fill does.
        'colors.surface' => 'background',
        'colors.text' => 'color',
        'colors.border' => 'border-color',
        'layout.gap.column' => 'column-gap',
        'layout.gap.row' => 'row-gap',
        'layout.gutter' => 'padding-inline',
    ];

    public static function hash(ThemeVocabulary $vocabulary): string
    {
        return substr(hash('sha256', json_encode([
            'vocabulary' => $vocabulary->values(),
            'schema' => Vocabulary::VERSION,
            'settings' => StyleSchema::VERSION,
            'compiler' => self::VERSION,
        ])), 0, 16);
    }

    public static function compile(ThemeVocabulary $vocabulary): string
    {
        $out = "@layer settings {\n";
        $out .= ":root {\n";
        foreach ($vocabulary->values() as $token => $value) {
            $out .= '  ' . self::variable($token) . ': ' . $value . ";\n";
        }
        $out .= "}\n";
        $out .= self::rules('base');
        foreach (self::MEDIA as $bp => $min) {
            $out .= "@media (min-width: {$min}px) {\n" . self::rules($bp) . "}\n";
        }
        return $out . "}\n";
    }

    /** `spacing.lg` → `--t-spacing-lg` */
    public static function variable(string $token): string
    {
        return '--t-' . str_replace('.', '-', $token);
    }

    private static function rules(string $bp): string
    {
        $out = '';
        foreach (StyleSchema::properties() as $path => $def) {
            if ($bp !== 'base' && !$def->responsive) {
                continue;
            }
            // A span has no rule of its own: spanRules() pairs it with the parent's track count.
            if ($path === 'layout.span') {
                continue;
            }
            foreach (self::valuesFor($path, $def->tokenDomain, $def->choices) as $value) {
                $declarations = self::declarations($path, $value);
                $out .= ClassNames::selector(ClassNames::for($path, $value, $bp)) . ' { ' . $declarations . " }\n";
            }
            if ($def->accepts(ValueKind::Reset)) {
                $reset = implode(' ', array_map(
                    static fn (string $property): string => "{$property}: revert-layer;",
                    self::cssProperties($path),
                ));
                $out .= ClassNames::selector(ClassNames::reset($path, $bp)) . ' { ' . $reset . " }\n";
            }
        }
        return $out . self::spanRules($bp);
    }

    /**
     * Span is never a single-class rule (container-layout spec §3.7): every rule pairs the
     * parent's track state with the child's span state AT THE SAME BREAKPOINT, so each breakpoint
     * has exactly one matching rule of equal specificity and a later one always wins — clamped to
     * unclamped, unclamped to clamped, and reset alike. `auto` and `reset` are the default track
     * state: the theme sets no `grid-template-columns`, so one track.
     */
    private static function spanRules(string $bp): string
    {
        $out = '';
        $tracks = self::TRACKS + ['auto' => 1, 'reset' => 1];
        $spans = array_merge(StyleSchema::property('layout.span')?->choices ?? [], ['reset']);
        foreach ($tracks as $cols => $count) {
            // PHP turns numeric array keys into ints; the class value is a string.
            $cols = (string) $cols;
            $parent = ClassNames::selector(ClassNames::for('layout.columns', $cols, $bp));
            foreach ($spans as $span) {
                $child = ClassNames::selector(ClassNames::for('layout.span', $span, $bp));
                $declaration = match (true) {
                    $span === 'reset' => 'grid-column: revert-layer;',
                    $span === 'full' => 'grid-column: 1 / -1;',
                    (int) $span > $count => 'grid-column: 1 / -1;',
                    default => 'grid-column: span ' . (int) $span . ';',
                };
                // The stage wraps every block root in a display:contents annotation element, so
                // the child combinator has to reach through it as well.
                $out .= $parent . ' > ' . $child . ",\n"
                    . $parent . ' > .thallo-preview-block > ' . $child
                    . ' { ' . $declaration . " }\n";
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function valuesFor(string $path, ?string $domain, ?array $choices): array
    {
        // A span only ever appears paired with a track count (spanRules), never alone.
        if ($path === 'layout.span') {
            return [];
        }
        if ($domain !== null) {
            return array_map(static fn (string $name): string => "{$domain}.{$name}", Vocabulary::names($domain));
        }
        // `auto` is the default track state the emitter writes when a container declares none.
        if ($path === 'layout.columns') {
            return array_merge($choices ?? [], ['auto']);
        }
        return $choices ?? [];
    }

    private static function declarations(string $path, string $value): string
    {
        if ($path === 'width') {
            return $value === 'width.full'
                ? 'max-width: none; width: 100%;'
                : 'max-width: var(' . self::variable($value) . ');';
        }
        // The content width also carries the gutter's default, so an absent gutter resolves to
        // the width's own default on this element and never to an ancestor's (spec §3.4).
        if ($path === 'layout.content_width') {
            $max = $value === 'width.full' ? 'none' : 'var(' . self::variable($value) . ')';
            $gutter = $value === 'width.full' ? '0px' : 'var(' . self::variable('spacing.lg') . ')';
            return "max-width: {$max}; margin-inline: auto; --thallo-default-gutter: {$gutter};";
        }
        if ($path === 'layout.columns') {
            if ($value === 'auto') {
                return 'grid-template-columns: none;';
            }
            $parts = array_map(
                static fn (string $part): string => 'minmax(0, ' . $part . 'fr)',
                explode('-', $value),
            );
            return count($parts) === 1 && self::TRACKS[$value] > 1
                ? 'grid-template-columns: repeat(' . self::TRACKS[$value] . ', minmax(0, 1fr));'
                : 'grid-template-columns: ' . implode(' ', $parts) . ';';
        }
        // Min height never writes `display`: managed visibility is the one authority over it
        // (spec §3.5). The theme's container rule reads --thallo-root-layout.
        if ($path === 'layout.min_height') {
            $height = ['auto' => 'auto', 'half' => '50vh', 'screen' => '100vh'][$value];
            $layout = $value === 'auto' ? 'block' : 'flex';
            return "min-height: {$height}; --thallo-root-layout: {$layout};";
        }
        if (isset(self::TOKEN_PROPERTY[$path])) {
            return self::TOKEN_PROPERTY[$path] . ': var(' . self::variable($value) . ');';
        }
        foreach (self::CHOICE_DECLARATIONS[$path] as $property => $map) {
            return "{$property}: {$map[$value]};";
        }
        throw new \LogicException("no declaration for {$path}");
    }

    /** @return list<string> the CSS properties a managed property writes */
    private static function cssProperties(string $path): array
    {
        if ($path === 'width') {
            return ['max-width', 'width'];
        }
        if ($path === 'layout.content_width') {
            return ['max-width', 'margin-inline', '--thallo-default-gutter'];
        }
        if ($path === 'layout.columns') {
            return ['grid-template-columns'];
        }
        if ($path === 'layout.min_height') {
            return ['min-height', '--thallo-root-layout'];
        }
        if ($path === 'layout.span') {
            return ['grid-column'];
        }
        if (isset(self::TOKEN_PROPERTY[$path])) {
            return [self::TOKEN_PROPERTY[$path]];
        }
        return array_keys(self::CHOICE_DECLARATIONS[$path]);
    }
}
