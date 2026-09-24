<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

/**
 * The one place a managed setting becomes a class name (visual builder spec §2.4). Utility
 * classes are the compiler's delivery mechanism, never the stored model: documents carry
 * typed settings and only this class knows `t-pt-lg`. Breakpoint variants are prefixed
 * (`md:t-pt-lg`, escaped `md\:t-pt-lg` in CSS); a reset is `t-pt-reset`.
 */
final class ClassNames
{
    /** property path => class stem */
    public const STEMS = [
        'spacing.padding.top' => 'pt',
        'spacing.padding.right' => 'pr',
        'spacing.padding.bottom' => 'pb',
        'spacing.padding.left' => 'pl',
        'spacing.margin.top' => 'mt',
        'spacing.margin.bottom' => 'mb',
        'width' => 'w',
        'alignment.text' => 'text',
        'alignment.content' => 'content',
        'alignment.self' => 'self',
        'typography.size' => 'size',
        'typography.weight' => 'weight',
        'visibility' => 'vis',
        'shadow' => 'shadow',
        'radius' => 'radius',
        'colors.surface' => 'bg',
        'colors.text' => 'fg',
        'colors.border' => 'bc',
        'border.width' => 'bw',
        'border.style' => 'bs',
        // Layout (container-layout spec §3.2).
        'layout.display' => 'display',
        'layout.direction' => 'dir',
        'layout.wrap' => 'wrap',
        'layout.align_items' => 'items',
        'layout.columns' => 'cols',
        'layout.gap.column' => 'gapx',
        'layout.gap.row' => 'gapy',
        'layout.content_width' => 'cw',
        'layout.gutter' => 'gutter',
        'layout.min_height' => 'minh',
        'layout.overflow' => 'overflow',
        'layout.span' => 'span',
        'layout.basis' => 'basis',
        'layout.grow' => 'grow',
        'layout.shrink' => 'shrink',
        'layout.align_self' => 'aself',
        'marker.radius' => 'mradius',
        'marker.shadow' => 'mshadow',
        'tabs.bar_radius' => 'barradius',
        'tabs.tab_radius' => 'tabradius',
        'aside.padding.top' => 'apadt',
        'aside.padding.right' => 'apadr',
        'aside.padding.bottom' => 'apadb',
        'aside.padding.left' => 'apadl',
        'aside.surface' => 'abg',
        'border.sides' => 'bsides',
        'colors.surface_opacity' => 'bgo',
        'backdrop.blur' => 'blur',
        'typography.line_height' => 'leading',
        'motion.entrance' => 'enter',
        'motion.duration' => 'enterdur',
        'motion.delay' => 'enterdelay',
        'motion.repeat' => 'enterrepeat',
        'motion.stagger' => 'stagger',
        'motion.ken_burns' => 'kenburns',
    ];

    /**
     * `spacing.lg` → `lg`; a choice value stays itself, except that a fraction becomes a class-safe
     * name: `layout.basis` `1/3` → `1-3`.
     */
    public static function valueName(string $value): string
    {
        $pos = strrpos($value, '.');
        $name = $pos === false ? $value : substr($value, $pos + 1);
        return str_replace('/', '-', $name);
    }

    /** The class for a property, a token or choice value, and a breakpoint. */
    public static function for(string $property, string $value, string $breakpoint = 'base'): string
    {
        $stem = self::STEMS[$property] ?? throw new \InvalidArgumentException("unknown managed property {$property}");
        $class = 't-' . $stem . '-' . self::valueName($value);
        return $breakpoint === 'base' ? $class : $breakpoint . ':' . $class;
    }

    public static function reset(string $property, string $breakpoint = 'base'): string
    {
        return self::for($property, 'reset', $breakpoint);
    }

    /** The CSS selector for a class (the breakpoint colon escaped). */
    public static function selector(string $class): string
    {
        return '.' . str_replace(':', '\\:', $class);
    }
}
