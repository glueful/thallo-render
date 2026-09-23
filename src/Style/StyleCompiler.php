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
    // 4: layout.display compiles flex and grid only (container-layout spec §11.1).
    // 7: a background utility names its colour (--t-surface) for the opacity utility to mix.
    // 8: typography.line_height.
    // 9: motion — entrances, and the one shared rule that animates them.
    // 10: a hero's aside — aside.padding and aside.surface.
    public const VERSION = 10;

    /** Where an entrance STARTS from; `none` starts nowhere. */
    private const ENTRANCES = [
        'fade' => 'none',
        'fade-up' => 'translateY(1.5rem)',
        'fade-down' => 'translateY(-1.5rem)',
        'slide-left' => 'translateX(2rem)',
        'slide-right' => 'translateX(-2rem)',
        'zoom-in' => 'scale(0.92)',
    ];
    private const MOTION_TIMES = [
        'motion.duration' => ['--t-enter-duration', ['fast' => 300, 'normal' => 600, 'slow' => 1000]],
        'motion.delay' => ['--t-enter-delay', ['none' => 0, 'short' => 150, 'medium' => 300, 'long' => 600]],
    ];
    /** The step between one child's entrance and the next, in ms. */
    private const STAGGER_STEPS = ['short' => 80, 'medium' => 150, 'long' => 250];
    /** Ken Burns: where the picture's drift starts and ends. */
    private const KEN_BURNS = [
        'zoom-in' => ['scale(1)', 'scale(1.15)'],
        'zoom-out' => ['scale(1.15)', 'scale(1)'],
        'pan-left' => ['scale(1.12) translateX(3%)', 'scale(1.12) translateX(-3%)'],
        'pan-right' => ['scale(1.12) translateX(-3%)', 'scale(1.12) translateX(3%)'],
    ];
    /** Past this child the stagger stops growing: a long list must not wait for seconds. */
    private const STAGGER_CAP = 12;

    /** `backdrop.blur` radii. */
    private const BLUR = ['none' => 'none', 'sm' => 'blur(4px)', 'md' => 'blur(12px)', 'lg' => 'blur(24px)'];

    /**
     * Modifiers: properties that adjust what ANOTHER property declares — the border's sides, the
     * surface's opacity. Theirs is the later rule, so they win over the utility they modify; and
     * their reset rule is EMPTY, because reverting the declarations they share would undo that
     * utility too. Absent, reset, or `all`: the modified property stands as it is — but every
     * class the emitter can write has a rule, so the empty ones are written.
     */
    private const MODIFIERS = ['border.sides', 'colors.surface_opacity'];

    /**
     * Properties whose utilities carry NO declaration for some or all values, and whose reset is
     * likewise empty: an entrance of `none`, the repeat (read by the page's script, not by CSS),
     * and the stagger (written on the CHILDREN by motionRules(), not on the element itself).
     */
    private const EMPTY_RESET = ['motion.entrance', 'motion.repeat', 'motion.stagger'];

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
        // Unitless: a line's height follows its text's size — the Size setting beside it.
        'typography.line_height' => ['line-height' => [
            'tight' => '1.1', 'snug' => '1.25', 'normal' => '1.5', 'relaxed' => '1.65', 'loose' => '1.9',
        ]],
        'border.width' => ['border-width' => ['none' => '0', 'thin' => '1px', 'thick' => '2px']],
        'border.style' => ['border-style' => ['solid' => 'solid', 'dashed' => 'dashed']],
        // Layout (container-layout spec §3.2). `layout.columns`, `layout.min_height`,
        // `layout.content_width` and `layout.span` are compiled by hand below: their declarations
        // are not one property = one value.
        'layout.display' => ['display' => ['flex' => 'flex', 'grid' => 'grid']],
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
        // The marker's own corners and shadow: the same declarations under their own class names,
        // since they land on the marker while `radius` and `shadow` land on the card.
        'marker.shadow' => 'box-shadow',
        'marker.radius' => 'border-radius',
        // A tab strip's corners, likewise: the bar's and the tab's, beside the panels area's.
        'tabs.bar_radius' => 'border-radius',
        'tabs.tab_radius' => 'border-radius',
        // A hero's aside: its padding and its fill, beside the band's own.
        'aside.padding' => 'padding',
        'aside.surface' => 'background',
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
        // The surface variables do not inherit: a child given only an opacity must not mix its
        // PARENT's colour.
        foreach (['--t-surface', '--t-surface-default'] as $name) {
            $out .= "@property {$name} { syntax: '*'; inherits: false; }\n";
        }
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
                $out .= ClassNames::selector(ClassNames::for($path, $value, $bp))
                    . ($declarations === '' ? " { }\n" : ' { ' . $declarations . " }\n");
            }
            $emptyReset = in_array($path, self::MODIFIERS, true) || in_array($path, self::EMPTY_RESET, true);
            if ($def->accepts(ValueKind::Reset) && $emptyReset) {
                $out .= ClassNames::selector(ClassNames::reset($path, $bp)) . " { }\n";
            } elseif ($def->accepts(ValueKind::Reset)) {
                $reset = implode(' ', array_map(
                    static fn (string $property): string => "{$property}: revert-layer;",
                    self::cssProperties($path),
                ));
                $out .= ClassNames::selector(ClassNames::reset($path, $bp)) . ' { ' . $reset . " }\n";
            }
        }
        return $out . self::spanRules($bp) . ($bp === 'base' ? self::motionRules() : '');
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

    /**
     * What makes the entrance utilities DO something (they only name a starting state): the
     * stagger of a container's children, and ONE rule that hides and animates every entrance.
     * It applies only where three things hold: the visitor has not asked for reduced motion; the
     * page's script has said it is running (`html[data-thallo-motion]`, which it sets only once
     * its observer exists — so without JavaScript, or if it fails, nothing is ever hidden); and
     * the block has not entered yet.
     */
    private static function motionRules(): string
    {
        $out = "@property --t-enter-stagger { syntax: '<time>'; inherits: false; initial-value: 0ms; }\n";
        foreach (self::STAGGER_STEPS as $name => $step) {
            $parent = ClassNames::selector(ClassNames::for('motion.stagger', $name));
            for ($k = 2; $k <= self::STAGGER_CAP; $k++) {
                // A <script> among the children is not a child that enters: it is not counted.
                $nth = $k === self::STAGGER_CAP ? "n+{$k}" : (string) $k;
                // The stage wraps every block root in a display:contents annotation element, which
                // takes the child's place: the delay reaches through it, as a span does.
                $place = ":nth-child({$nth} of :not(script))";
                $out .= "{$parent} > {$place},\n{$parent} > .thallo-preview-block{$place} > *"
                    . ' { --t-enter-stagger: ' . (($k - 1) * $step) . "ms; }\n";
            }
        }
        $entrances = ':is(' . implode(', ', array_map(
            static fn (string $name): string => ClassNames::selector(ClassNames::for('motion.entrance', $name)),
            array_keys(self::ENTRANCES),
        )) . ')';
        // Ken Burns: the picture that is the frame's DIRECT child drifts — never one deeper inside
        // (a container's content is not its background). Where a drift starts and ends is only a
        // pair of values, so it stands outside the reduced-motion query: the editor's Play
        // replays a drift by hand and reads them.
        $picture = ' > :is(img, picture, video)';
        foreach (self::KEN_BURNS as $name => [$from, $to]) {
            $out .= ClassNames::selector(ClassNames::for('motion.ken_burns', $name))
                . "{$picture} { --t-kb-from: {$from}; --t-kb-to: {$to}; }\n";
        }
        $out .= "@media (prefers-reduced-motion: no-preference) {\n";
        $out .= "html[data-thallo-motion] {$entrances}:not([data-thallo-entered]) { opacity: 0; "
            . "transform: var(--t-enter-transform, none); }\n";
        $out .= "html[data-thallo-motion] {$entrances} { transition: opacity var(--t-enter-duration, 600ms) "
            . 'ease-out, transform var(--t-enter-duration, 600ms) cubic-bezier(0.2, 0.7, 0.2, 1); '
            . "transition-delay: calc(var(--t-enter-delay, 0ms) + var(--t-enter-stagger, 0ms)); }\n";
        $frames = ':is(' . implode(', ', array_map(
            static fn (string $name): string => ClassNames::selector(ClassNames::for('motion.ken_burns', $name)),
            array_keys(self::KEN_BURNS),
        )) . ')';
        // One drift there and back, then it rests; it holds still under the pointer or keyboard
        // focus. Motion that runs on its own for longer than five seconds must be stoppable
        // (WCAG 2.2.2), and a background that never settles is the one visitors cannot stop.
        $out .= "{$frames}{$picture} { animation: t-kenburns 20s ease-in-out 2 alternate both; "
            . "transform-origin: center; }\n";
        $out .= "{$frames}:is(:hover, :focus-within){$picture} { animation-play-state: paused; }\n";
        $out .= "@keyframes t-kenburns { from { transform: var(--t-kb-from); } "
            . "to { transform: var(--t-kb-to); } }\n";
        return $out . "}\n";
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
            // `width` means "fill the available space, up to this maximum" (spec §11.4): it asks
            // for the width as well as limiting it. A block box fills its container unasked, but a
            // flex item whose inline margins are `auto` (Placement) shrinks to its content, so
            // inside a flex column a placed child would be as wide as its text. In a flex row this
            // is the item's starting size under `flex-basis: auto`. It renders as `auto` did in
            // block flow only under border-box sizing, which is the theme's to provide.
            return $value === 'width.full'
                ? 'max-width: none; width: 100%;'
                : 'max-width: var(' . self::variable($value) . '); width: 100%;';
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
        if ($path === 'motion.entrance') {
            return isset(self::ENTRANCES[$value]) ? '--t-enter-transform: ' . self::ENTRANCES[$value] . ';' : '';
        }
        if (isset(self::MOTION_TIMES[$path])) {
            [$variable, $times] = self::MOTION_TIMES[$path];
            return "{$variable}: {$times[$value]}ms;";
        }
        if ($path === 'motion.repeat' || $path === 'motion.stagger') {
            return '';
        }
        // The class lands on the FRAME, which clips; motionRules() moves the picture inside it.
        if ($path === 'motion.ken_burns') {
            return isset(self::KEN_BURNS[$value]) ? 'overflow: clip;' : '';
        }
        if ($path === 'border.sides' && $value === 'all') {
            return ''; // what the width utility declares already
        }
        if ($path === 'border.sides') {
            $others = array_diff(['top', 'right', 'bottom', 'left'], [$value]);
            return implode(' ', array_map(static fn (string $side): string => "border-{$side}-width: 0;", $others));
        }
        // The colour the element shows is the one chosen for it, else the one the theme names for
        // it (--t-surface-default), else none — so an opacity alone changes nothing a theme did
        // not paint.
        if ($path === 'colors.surface_opacity') {
            return 'background: color-mix(in srgb, var(--t-surface, var(--t-surface-default, transparent)) '
                . $value . '%, transparent);';
        }
        if ($path === 'backdrop.blur') {
            $blur = self::BLUR[$value];
            return "backdrop-filter: {$blur}; -webkit-backdrop-filter: {$blur};";
        }
        // A background names its colour as well as painting it: the opacity utility mixes from it.
        if ($path === 'colors.surface') {
            $colour = 'var(' . self::variable($value) . ')';
            return "--t-surface: {$colour}; background: {$colour};";
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
        if ($path === 'backdrop.blur') {
            return ['backdrop-filter', '-webkit-backdrop-filter'];
        }
        if (isset(self::MOTION_TIMES[$path])) {
            return [self::MOTION_TIMES[$path][0]];
        }
        if ($path === 'motion.ken_burns') {
            return ['overflow'];
        }
        if (isset(self::TOKEN_PROPERTY[$path])) {
            return [self::TOKEN_PROPERTY[$path]];
        }
        return array_keys(self::CHOICE_DECLARATIONS[$path]);
    }
}
