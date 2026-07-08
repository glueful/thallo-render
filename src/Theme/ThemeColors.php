<?php

declare(strict_types=1);

namespace Thallo\Render\Theme;

/**
 * Theme-color config (theme-color-config spec §3): maps a closed accent/neutral
 * family pair to concrete design-token hex, light + dark. Pure + static — no CSS
 * is emitted for the frozen default pair (blue/slate), which lives canonically in
 * site.css. Every value comes from Tailwind's published palette EXCEPT the slate
 * rows, which reproduce the shipped site.css values byte-for-byte.
 */
final class ThemeColors
{
    public const DEFAULT_ACCENT = 'blue';
    public const DEFAULT_NEUTRAL = 'slate';

    /** @var list<string> */
    public const ACCENTS = [
        'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal',
        'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose',
    ];

    /** @var list<string> */
    public const NEUTRALS = ['slate', 'gray', 'zinc', 'neutral', 'stone'];

    /**
     * Accent fill per family: [light, dark]. accent-ink is white uniformly, so
     * light-hued families (amber/yellow/lime) use DARKER stops to keep white text
     * at AA — enforced by ThemeColorsTest::testWhiteAccentInkMeetsContrast...
     * @var array<string,array{0:string,1:string}>
     */
    private const ACCENT = [
        // Light stop is chosen so white --accent-ink meets WCAG AA on the fill;
        // bright hues use their 700 stop (contrast checked in ThemeColorsTest).
        'red'     => ['#dc2626', '#ef4444'],
        'orange'  => ['#c2410c', '#f97316'], // orange-700/500
        'amber'   => ['#b45309', '#d97706'], // amber-700/600
        'yellow'  => ['#a16207', '#ca8a04'], // yellow-700/600
        'lime'    => ['#4d7c0f', '#65a30d'], // lime-700/600
        'green'   => ['#15803d', '#22c55e'], // green-700/500
        'emerald' => ['#047857', '#10b981'], // emerald-700/500
        'teal'    => ['#0f766e', '#14b8a6'], // teal-700/500
        'cyan'    => ['#0e7490', '#06b6d4'], // cyan-700/500
        'sky'     => ['#0369a1', '#0ea5e9'], // sky-700/500
        'blue'    => ['#2563eb', '#3b82f6'],
        'indigo'  => ['#4f46e5', '#6366f1'],
        'violet'  => ['#7c3aed', '#8b5cf6'],
        'purple'  => ['#9333ea', '#a855f7'],
        'fuchsia' => ['#c026d3', '#d946ef'],
        'pink'    => ['#db2777', '#ec4899'],
        'rose'    => ['#e11d48', '#f43f5e'],
    ];

    /**
     * Neutral token stops per family (light): bg is always white; other stops
     * follow 50/100/900/500/200. slate is frozen to the shipped site.css values.
     * @var array<string,array<string,string>>
     */
    // phpcs:disable Generic.Files.LineLength.TooLong -- intentional one-line-per-family palette tables
    private const NEUTRAL_LIGHT = [
        'slate'   => ['--bg' => '#ffffff', '--surface' => '#f6f7f9', '--surface-2' => '#eef0f4', '--ink' => '#0f172a', '--muted' => '#64748b', '--line' => '#e2e8f0'],
        'gray'    => ['--bg' => '#ffffff', '--surface' => '#f9fafb', '--surface-2' => '#f3f4f6', '--ink' => '#111827', '--muted' => '#6b7280', '--line' => '#e5e7eb'],
        'zinc'    => ['--bg' => '#ffffff', '--surface' => '#fafafa', '--surface-2' => '#f4f4f5', '--ink' => '#18181b', '--muted' => '#71717a', '--line' => '#e4e4e7'],
        'neutral' => ['--bg' => '#ffffff', '--surface' => '#fafafa', '--surface-2' => '#f5f5f5', '--ink' => '#171717', '--muted' => '#737373', '--line' => '#e5e5e5'],
        'stone'   => ['--bg' => '#ffffff', '--surface' => '#fafaf9', '--surface-2' => '#f5f5f4', '--ink' => '#1c1917', '--muted' => '#78716c', '--line' => '#e7e5e4'],
    ];

    /**
     * Neutral token stops per family (dark): slate is frozen to site.css; non-slate
     * families use bg=950 surface=900 surface-2=800 ink=200 muted=400 line=800.
     * @var array<string,array<string,string>>
     */
    private const NEUTRAL_DARK = [
        'slate'   => ['--bg' => '#0b1120', '--surface' => '#111a2e', '--surface-2' => '#16213a', '--ink' => '#e2e8f0', '--muted' => '#94a3b8', '--line' => '#1e293b'],
        'gray'    => ['--bg' => '#030712', '--surface' => '#111827', '--surface-2' => '#1f2937', '--ink' => '#e5e7eb', '--muted' => '#9ca3af', '--line' => '#1f2937'],
        'zinc'    => ['--bg' => '#09090b', '--surface' => '#18181b', '--surface-2' => '#27272a', '--ink' => '#e4e4e7', '--muted' => '#a1a1aa', '--line' => '#27272a'],
        'neutral' => ['--bg' => '#0a0a0a', '--surface' => '#171717', '--surface-2' => '#262626', '--ink' => '#e5e5e5', '--muted' => '#a3a3a3', '--line' => '#262626'],
        'stone'   => ['--bg' => '#0c0a09', '--surface' => '#1c1917', '--surface-2' => '#292524', '--ink' => '#e7e5e4', '--muted' => '#a8a29e', '--line' => '#292524'],
    ];
    // phpcs:enable Generic.Files.LineLength.TooLong

    public static function normalizeAccent(string $v): ?string
    {
        return in_array($v, self::ACCENTS, true) ? $v : null;
    }

    public static function normalizeNeutral(string $v): ?string
    {
        return in_array($v, self::NEUTRALS, true) ? $v : null;
    }

    /**
     * The 8 token values for a validated pair in one mode ('light'|'dark').
     *
     * @return array<string,string>
     */
    public static function tokens(string $accent, string $neutral, string $mode): array
    {
        return self::neutralVars($neutral, $mode) + self::accentVars($accent, $mode);
    }

    /** Override CSS for a validated pair, or '' when it is the default. */
    public static function css(string $accent, string $neutral): string
    {
        if ($accent === self::DEFAULT_ACCENT && $neutral === self::DEFAULT_NEUTRAL) {
            return '';
        }
        return ':root{' . self::declarations(self::tokens($accent, $neutral, 'light')) . '}'
            . 'html[data-theme="dark"]{' . self::declarations(self::tokens($accent, $neutral, 'dark')) . '}';
    }

    /**
     * Deterministic scope class for a scoped re-skin, or '' when neither resolves.
     * An unset/invalid dimension becomes the literal 'none' (style-block spec §4.1).
     */
    public static function skinClass(?string $accent, ?string $neutral): string
    {
        $a = self::normalizeAccent($accent ?? '');
        $n = self::normalizeNeutral($neutral ?? '');
        if ($a === null && $n === null) {
            return '';
        }
        return 'thallo-skin-' . ($a ?? 'none') . '-' . ($n ?? 'none');
    }

    /**
     * Scoped CSS re-skinning ONLY the set dimensions, following the global mode:
     *   .scope{ <light> } html[data-theme="dark"] .scope{ <dark> }
     * Returns '' when neither accent nor neutral resolves (style-block spec §4.1).
     */
    public static function scopedCss(?string $accent, ?string $neutral, string $scopeClass): string
    {
        $a = self::normalizeAccent($accent ?? '');
        $n = self::normalizeNeutral($neutral ?? '');
        if ($a === null && $n === null) {
            return '';
        }
        $vars = static function (string $mode) use ($a, $n): array {
            return ($n !== null ? self::neutralVars($n, $mode) : [])
                + ($a !== null ? self::accentVars($a, $mode) : []);
        };
        return '.' . $scopeClass . '{' . self::declarations($vars('light')) . '}'
            . 'html[data-theme="dark"] .' . $scopeClass . '{' . self::declarations($vars('dark')) . '}';
    }

    /**
     * The --accent / --accent-ink pair for one mode.
     *
     * @return array<string,string>
     */
    private static function accentVars(string $accent, string $mode): array
    {
        [$light, $dark] = self::ACCENT[$accent];
        return ['--accent' => $mode === 'dark' ? $dark : $light, '--accent-ink' => '#ffffff'];
    }

    /**
     * The six neutral vars for one mode.
     *
     * @return array<string,string>
     */
    private static function neutralVars(string $neutral, string $mode): array
    {
        return $mode === 'dark' ? self::NEUTRAL_DARK[$neutral] : self::NEUTRAL_LIGHT[$neutral];
    }

    /** @param array<string,string> $tokens */
    private static function declarations(array $tokens): string
    {
        $decls = '';
        foreach ($tokens as $name => $value) {
            $decls .= "{$name}:{$value};";
        }
        return $decls;
    }
}
