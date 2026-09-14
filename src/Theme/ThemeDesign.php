<?php

declare(strict_types=1);

namespace Thallo\Render\Theme;

/**
 * Site-wide design tokens (website plan phase 1b): corner radius, typeface pairing and
 * page ground, chosen in Settings next to the theme colours. Closed enums; the defaults
 * reproduce the shipped theme exactly and emit nothing, the same contract as
 * {@see ThemeColors::css()}. Fonts are system stacks: the site's CSP is 'self'.
 */
final class ThemeDesign
{
    public const RADII = ['sharp', 'soft', 'round'];
    public const FONTS = ['sans', 'editorial', 'serif'];
    public const BACKGROUNDS = ['plain', 'tinted'];

    public const DEFAULT_RADIUS = 'round';
    public const DEFAULT_FONT = 'sans';
    public const DEFAULT_BACKGROUND = 'plain';

    private const SERIF = '"Iowan Old Style","Palatino Linotype","Book Antiqua",Georgia,serif';

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

    public static function normalizeBackground(string $v): ?string
    {
        return in_array($v, self::BACKGROUNDS, true) ? $v : null;
    }

    /**
     * Override CSS for validated choices, or '' when every choice is the default. Light-mode
     * tokens only: dark mode keeps the theme's own dark ground, and the theme's
     * html[data-theme="dark"] rule outranks :root.
     */
    public static function css(string $radius, string $font, string $background, string $neutral): string
    {
        $tokens = self::RADIUS_TOKENS[$radius] ?? [];

        if ($font === 'editorial') {
            $tokens['--font-display'] = self::SERIF;
        } elseif ($font === 'serif') {
            $tokens['--font-display'] = self::SERIF;
            $tokens['--font-body'] = self::SERIF;
        }

        if ($background === 'tinted') {
            $light = ThemeColors::neutralTokens($neutral, 'light');
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
