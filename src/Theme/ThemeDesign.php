<?php

declare(strict_types=1);

namespace Thallo\Render\Theme;

use Thallo\Contracts\Style\FontStacks;

/**
 * Site-wide design tokens (website plan phase 1b): corner radius, typeface pairing and
 * page ground, chosen in Settings next to the theme colours. Closed enums; the defaults
 * reproduce the shipped theme exactly and emit nothing, the same contract as
 * {@see ThemeColors::css()}.
 *
 * Every pairing but `custom` is a system stack — it downloads no font of its own and the site's
 * CSP stays 'self'. `editorial` and `slab` set only the headings, so the text keeps the theme's
 * face and the layout still downloads it (usesThemeFace()), as it does for the default `sans`.
 * `custom` is the site's OWN faces: a woff2 for the text, one for the headings, or both,
 * uploaded to the media library and served from the site.
 */
final class ThemeDesign
{
    public const RADII = ['sharp', 'soft', 'round'];
    public const FONTS = ['sans', 'editorial', 'serif', 'humanist', 'geometric', 'slab', 'mono', 'system', 'custom'];
    public const BACKGROUNDS = ['plain', 'tinted'];

    public const DEFAULT_RADIUS = 'round';
    public const DEFAULT_FONT = 'sans';
    public const DEFAULT_BACKGROUND = 'plain';

    // The named stacks are shared with the font library and the typeface utilities (FontStacks).
    private const SERIF = FontStacks::SERIF;
    private const SYSTEM = FontStacks::SYSTEM;
    private const HUMANIST = FontStacks::HUMANIST;
    private const GEOMETRIC = FontStacks::GEOMETRIC;
    private const SLAB = FontStacks::SLAB;
    private const MONO = FontStacks::MONO;

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

    /** A font library ID (a built-in name or a family's 12 letters and digits), or null. */
    public static function normalizeFamily(string $v): ?string
    {
        return preg_match('/\A(?:theme|serif|humanist|geometric|slab|mono|system|[A-Za-z0-9]{12})\z/', $v) === 1
            ? $v
            : null;
    }

    public static function normalizeBackground(string $v): ?string
    {
        return in_array($v, self::BACKGROUNDS, true) ? $v : null;
    }

    /**
     * Whether the theme's own text face is still what the site is set in. The layout preloads
     * that face; a site whose text is another face entirely would download it for nothing.
     *
     * @param array{stack: string, synthesis: string}|null $text Custom's resolved Text family
     */
    public static function usesThemeFace(string $font, ?array $text = null): bool
    {
        if ($font === 'custom') {
            return $text === null;
        }
        return !isset(self::PAIRINGS[$font]) || self::PAIRINGS[$font][1] === null;
    }

    /**
     * Override CSS for validated choices, or '' when every choice is the default. Light-mode
     * tokens only: dark mode keeps the theme's own dark ground, and the theme's
     * html[data-theme="dark"] rule outranks :root.
     *
     * The `custom` pairing takes its Text and Headings from the font library (block typeface spec
     * §2.8), each resolved to its stack and synthesis policy (`style` for an uploaded family, the
     * browser's `weight style` for a built-in); their faces are declared by the workspace's fonts
     * stylesheet. Headings without a family follow the Text; no Text leaves the theme's face.
     *
     * Under a Custom neutral (custom palette spec §2.2) Tinted swaps the six custom values' Background
     * and Surface, exactly as it swaps a family's.
     *
     * @param array{stack: string, synthesis: string}|null $text
     * @param array{stack: string, synthesis: string}|null $headings
     * @param array<string,string>|null $customNeutral the palette's six light values, when Custom
     */
    public static function css(
        string $radius,
        string $font,
        string $background,
        string $neutral,
        ?array $text = null,
        ?array $headings = null,
        ?array $customNeutral = null,
    ): string {
        $tokens = self::RADIUS_TOKENS[$radius] ?? [];

        if ($font === 'custom') {
            $headings ??= $text;
            foreach (['body' => $text, 'display' => $headings] as $role => $family) {
                if ($family !== null) {
                    $tokens['--font-' . $role] = $family['stack'];
                    $tokens['--font-synthesis-' . $role] = $family['synthesis'];
                }
            }
        } elseif (isset(self::PAIRINGS[$font])) {
            [$display, $body] = self::PAIRINGS[$font];
            $tokens['--font-display'] = $display;
            if ($body !== null) {
                $tokens['--font-body'] = $body;
            }
        }

        if ($background === 'tinted') {
            $light = $customNeutral !== null && $neutral === 'custom'
                ? ThemeColors::customVars($customNeutral)
                : ThemeColors::neutralTokens($neutral, 'light');
            $tokens['--bg'] = $light['--surface'];
            $tokens['--surface'] = $light['--bg'];
        }

        if ($tokens === []) {
            return '';
        }

        $out = '';
        foreach ($tokens as $name => $value) {
            $out .= ($out === '' ? '' : ';') . $name . ':' . $value;
        }

        return ':root{' . $out . '}';
    }
}
