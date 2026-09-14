<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Style\PropertyDefinition;
use Thallo\Contracts\Style\StyleSchema;

/**
 * Thallo's breakpoint-first cascade (visual builder spec §1.6). Managed layers in rising
 * precedence are each reusable class in list order, then the instance; the theme default is the
 * fallback outside them. For a target breakpoint, walk from it down to `base`: at the first
 * breakpoint where any managed layer declares the property exactly, take the highest-precedence
 * declaration; a reset there terminates resolution to the theme default; if no breakpoint
 * declares anything, the theme default applies. A non-responsive property resolves at `base`
 * only. Mirrored in `admin/src/style/resolver.ts` against one fixture set.
 */
final class CascadeResolver
{
    /**
     * @param list<array{id: string, style: array<string,mixed>}> $classes ordered, lowest precedence first
     * @param array<string,mixed> $instance the block's `settings.style`
     * @return array<string, Resolution> keyed by breakpoint (`base` only for non-responsive)
     */
    public function resolve(
        string $property,
        array $classes,
        array $instance,
        PropertyDefinition $def,
    ): array {
        $layers = $this->layers($property, $classes, $instance, $def->responsive);
        $breakpoints = $def->responsive ? StyleSchema::BREAKPOINTS : ['base'];
        $out = [];
        foreach ($breakpoints as $target) {
            $out[$target] = $this->resolveAt($target, $layers, $breakpoints);
        }
        return $out;
    }

    /**
     * @param list<array{layer: string, declarations: array<string, array<string,mixed>>}> $layers
     *   highest precedence first
     * @param list<string> $breakpoints
     */
    private function resolveAt(string $target, array $layers, array $breakpoints): Resolution
    {
        $index = array_search($target, $breakpoints, true);
        for ($i = (int) $index; $i >= 0; $i--) {
            $bp = $breakpoints[$i];
            foreach ($layers as $layer) {
                $value = $layer['declarations'][$bp] ?? null;
                if ($value === null) {
                    continue;
                }
                if (($value['type'] ?? null) === 'reset') {
                    return Resolution::reset($layer['layer'], $target);
                }
                return Resolution::managed($value, $layer['layer'], $bp === $target, $target);
            }
        }
        return Resolution::themeDefault($target);
    }

    /**
     * Each layer's declarations for the property keyed by breakpoint, highest precedence first.
     *
     * @param list<array{id: string, style: array<string,mixed>}> $classes
     * @param array<string,mixed> $instance
     * @return list<array{layer: string, declarations: array<string, array<string,mixed>>}>
     */
    private function layers(string $property, array $classes, array $instance, bool $responsive): array
    {
        $layers = [['layer' => 'instance', 'declarations' => $this->declarations($instance, $property, $responsive)]];
        for ($i = count($classes) - 1; $i >= 0; $i--) {
            $layers[] = [
                'layer' => 'class:' . (string) ($classes[$i]['id'] ?? ''),
                'declarations' => $this->declarations((array) ($classes[$i]['style'] ?? []), $property, $responsive),
            ];
        }
        return $layers;
    }

    /**
     * @param array<string,mixed> $style
     * @return array<string, array<string,mixed>> breakpoint => typed value
     */
    private function declarations(array $style, string $property, bool $responsive): array
    {
        $node = $style;
        foreach (explode('.', $property) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return [];
            }
            $node = $node[$part];
        }
        if (!is_array($node)) {
            return [];
        }
        if (!$responsive) {
            return isset($node['type']) ? ['base' => $node] : [];
        }
        $out = [];
        foreach (StyleSchema::BREAKPOINTS as $bp) {
            if (isset($node[$bp]) && is_array($node[$bp]) && isset($node[$bp]['type'])) {
                $out[$bp] = $node[$bp];
            }
        }
        return $out;
    }
}
