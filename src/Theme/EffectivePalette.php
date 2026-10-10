<?php

declare(strict_types=1);

namespace Thallo\Render\Theme;

use Thallo\Contracts\Style\Palette;

/**
 * The values the site would render (custom palette spec §6): Plain/Tinted applied, the dark base
 * resolved, brand fills and inks derived — the one source for the contrast checks and the
 * pickers' swatches. Each mode is keyed by colour token name (`surface-2`, `brand-1-contrast`…).
 */
final class EffectivePalette
{
    private const VAR_OF = [
        'background' => '--bg', 'surface' => '--surface', 'surface-2' => '--surface-2', 'text' => '--ink',
        'muted' => '--muted', 'line' => '--line', 'accent' => '--accent', 'accent-contrast' => '--accent-ink',
    ];

    /** @param array<string,array<string,string>> $modes */
    private function __construct(private readonly array $modes, private readonly Palette $palette)
    {
    }

    public static function of(string $accent, string $neutral, string $background, Palette $palette): self
    {
        $custom = $neutral === 'custom' && $palette->customNeutral !== null;
        $family = ThemeColors::normalizeNeutral($neutral) ?? ThemeColors::DEFAULT_NEUTRAL;
        $darkFamily = $custom ? ($palette->darkBase ?? ThemeColors::DEFAULT_NEUTRAL) : $family;
        $accent = ThemeColors::normalizeSiteAccent($accent) ?? ThemeColors::DEFAULT_ACCENT;
        $light = $custom
            ? ThemeColors::customVars($palette->customNeutral ?? [])
            : ThemeColors::neutralTokens($family, 'light');
        $vars = [
            'light' => $light + ThemeColors::tokens($accent, $darkFamily, 'light'),
            'dark' => ThemeColors::tokens($accent, $darkFamily, 'dark'),
        ];
        if ($background === 'tinted') {
            $ground = $vars['light']['--bg'];
            $vars['light']['--bg'] = $vars['light']['--surface'];
            $vars['light']['--surface'] = $ground;
        }
        $ground = $vars['dark']['--bg'];
        $modes = [];
        foreach ($vars as $mode => $v) {
            foreach (self::VAR_OF as $name => $var) {
                $modes[$mode][$name] = $v[$var];
            }
            $modes[$mode]['white'] = '#ffffff';
            $modes[$mode]['black'] = '#000000';
            foreach ($palette->configured() as $slot => $brand) {
                [$fill, $ink] = ThemeColors::brandVars($brand->hex, $mode, $ground);
                $modes[$mode]["brand-{$slot}"] = $fill;
                $modes[$mode]["brand-{$slot}-contrast"] = $ink;
            }
        }
        return new self($modes, $palette);
    }

    /** @return array<string,string> token name => #rrggbb */
    public function values(string $mode): array
    {
        return $this->modes[$mode];
    }

    /** @return list<array{fg:string,on:string,mode:string,ratio:float,passes:bool}> */
    public function contrastRows(): array
    {
        $pairs = [];
        foreach (['text', 'muted'] as $fg) {
            foreach (['background', 'surface', 'surface-2'] as $on) {
                $pairs[] = [$fg, $on];
            }
        }
        $pairs[] = ['accent', 'background'];
        $pairs[] = ['accent-contrast', 'accent'];
        foreach ($this->palette->ids() as $slot) {
            $pairs[] = ["brand-{$slot}", 'background'];
            $pairs[] = ["brand-{$slot}-contrast", "brand-{$slot}"];
        }
        $rows = [];
        foreach (['light', 'dark'] as $mode) {
            foreach ($pairs as [$fg, $on]) {
                $ratio = round(ThemeColors::contrast($this->modes[$mode][$fg], $this->modes[$mode][$on]), 2);
                $rows[] = ['fg' => $fg, 'on' => $on, 'mode' => $mode, 'ratio' => $ratio, 'passes' => $ratio >= 4.5];
            }
        }
        return $rows;
    }

    /** @return array<string,string> `color.<name>` => light hex; brand slots travel in the palette block */
    public function swatches(): array
    {
        $out = [];
        foreach ($this->modes['light'] as $name => $hex) {
            if (!str_starts_with($name, 'brand-')) {
                $out['color.' . $name] = $hex;
            }
        }
        return $out;
    }
}
