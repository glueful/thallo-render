<?php

declare(strict_types=1);

namespace Thallo\Render\Theme;

/**
 * Site-wide design tokens (website plan phase 1b): corner radius, typeface pairing and
 * page ground, chosen in Settings next to the theme colours. Closed enums; the defaults
 * reproduce the shipped theme exactly and emit nothing, the same contract as
 * {@see ThemeColors::css()}.
 *
 * Every pairing but `custom` is a system stack — it costs a visitor nothing and the site's CSP
 * stays 'self'. `custom` is the site's OWN faces: a woff2 for the text, one for the headings, or
 * both, uploaded to the media library and served from the site.
 */
final class ThemeDesign
{
    public const RADII = ['sharp', 'soft', 'round'];
    public const FONTS = ['sans', 'editorial', 'serif', 'humanist', 'geometric', 'slab', 'mono', 'system', 'custom'];
    public const BACKGROUNDS = ['plain', 'tinted'];

    public const DEFAULT_RADIUS = 'round';
    public const DEFAULT_FONT = 'sans';
    public const DEFAULT_BACKGROUND = 'plain';

    private const SERIF = '"Iowan Old Style","Palatino Linotype","Book Antiqua",Georgia,serif';
    private const SYSTEM = 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    private const HUMANIST = 'Seravek,"Gill Sans Nova",Ubuntu,Calibri,"DejaVu Sans",source-sans-pro,sans-serif';
    private const GEOMETRIC = 'Avenir,Montserrat,Corbel,"URW Gothic",source-sans-pro,sans-serif';
    private const SLAB = 'Rockwell,"Rockwell Nova","Roboto Slab","DejaVu Serif","Sitka Small",serif';
    private const MONO = 'ui-monospace,"Cascadia Code","Source Code Pro",Menlo,Consolas,"DejaVu Sans Mono",monospace';

    /**
     * A pairing as [display, body]; null leaves that role to the theme's own face.
     *
     * @var array<string,array{0:?string,1:?string}>
     */
    private const PAIRINGS = [
        'editorial' => [self::SERIF, null],
        'serif' => [self::SERIF, self::SERIF],
        'humanist' => [self::HUMANIST, self::HUMANIST],
        'geometric' => [self::GEOMETRIC, self::GEOMETRIC],
        'slab' => [self::SLAB, null],
        'mono' => [self::MONO, self::MONO],
        'system' => [self::SYSTEM, self::SYSTEM],
    ];

    /** @var array<string,array<string,string>> */
    private const RADIUS_TOKENS = [
        'sharp' => ['--radius' => '4px', '--radius-lg' => '8px', '--radius-btn' => '4px'],
        'soft' => ['--radius' => '12px', '--radius-lg' => '20px', '--radius-btn' => '8px'],
    ];

    public static function normalizeRadius(string $v): ?string
    {
        return in_array($v, self::RADII, true) ? $v : null;
    }

    public static function normalizeFont(string $v): ?string
    {
        return in_array($v, self::FONTS, true) ? $v : null;
    }

    /** A media library uuid naming one of the site's own faces, or null for anything else. */
    public static function normalizeFace(string $v): ?string
    {
        return preg_match('/\A[A-Za-z0-9_-]{8,40}\z/', $v) === 1 ? $v : null;
    }

    public static function normalizeBackground(string $v): ?string
    {
        return in_array($v, self::BACKGROUNDS, true) ? $v : null;
    }

    /**
     * Whether the theme's own text face is still what the site is set in. The layout preloads
     * that face; a site whose text is another face entirely would download it for nothing.
     *
     * @param array{body?: string, display?: string} $faces the site's own faces (`custom`)
     */
    public static function usesThemeFace(string $font, array $faces): bool
    {
        if ($font === 'custom') {
            return !isset($faces['body']);
        }
        return !isset(self::PAIRINGS[$font]) || self::PAIRINGS[$font][1] === null;
    }

    /**
     * Override CSS for validated choices, or '' when every choice is the default. Light-mode
     * tokens only: dark mode keeps the theme's own dark ground, and the theme's
     * html[data-theme="dark"] rule outranks :root.
     *
     * @param array{body?: string, display?: string} $faces URLs of the site's own woff2 faces,
     *        used only by the `custom` choice
     */
    public static function css(
        string $radius,
        string $font,
        string $background,
        string $neutral,
        array $faces = [],
    ): string {
        $tokens = self::RADIUS_TOKENS[$radius] ?? [];
        $fontFaces = '';

        if ($font === 'custom') {
            foreach (['body' => 'Site Body', 'display' => 'Site Display'] as $role => $family) {
                if (isset($faces[$role]) && $faces[$role] !== '') {
                    // One file serves every weight: a variable font covers the range itself, and
                    // a single-weight file is better shown as it is than faked bold by the browser.
                    $fontFaces .= '@font-face{font-family:"' . $family . '";src:url("'
                        . self::cssString($faces[$role]) . '") format("woff2");'
                        . 'font-weight:100 900;font-display:swap}';
                    $tokens['--font-' . $role] = '"' . $family . '",' . self::SYSTEM;
                }
            }
            // Only a text face: headings follow it, as they follow the body in the theme.
            if (isset($tokens['--font-body']) && !isset($tokens['--font-display'])) {
                $tokens['--font-display'] = $tokens['--font-body'];
            }
        } elseif (isset(self::PAIRINGS[$font])) {
            [$display, $body] = self::PAIRINGS[$font];
            $tokens['--font-display'] = $display;
            if ($body !== null) {
                $tokens['--font-body'] = $body;
            }
        }

        if ($background === 'tinted') {
            $light = ThemeColors::neutralTokens($neutral, 'light');
            $tokens['--bg'] = $light['--surface'];
            $tokens['--surface'] = $light['--bg'];
        }

        if ($tokens === []) {
            return $fontFaces;
        }

        $out = '';
        foreach ($tokens as $name => $value) {
            $out .= ($out === '' ? '' : ';') . $name . ':' . $value;
        }

        return $fontFaces . ':root{' . $out . '}';
    }

    /** A value inside a CSS string: backslash-hex for anything that could end the string or the sheet. */
    private static function cssString(string $value): string
    {
        return (string) preg_replace_callback(
            '/[\x00-\x1F\x7F"\'\\\\<>(){};]/',
            static fn (array $m): string => sprintf('\\%x ', ord($m[0])),
            $value,
        );
    }
}
