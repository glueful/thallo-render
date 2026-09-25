<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Style\CascadeResolver;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Contracts\Style\StyleTargets;

/**
 * Turns a block's typed settings into what a template emits per target (visual builder spec
 * §2.5): the utility classes for every managed property the target owns, one per breakpoint the
 * cascade declares exactly (the compiled artifact is mobile-first, so an inherited value needs
 * no class), the author's own classes when the target owns `advanced.css_classes`, and only the
 * attributes the target owns. The resolver decides; this class only names classes.
 */
final class BlockStyleEmitter
{
    public function __construct(private readonly CascadeResolver $resolver = new CascadeResolver())
    {
    }

    /**
     * @param array<string,mixed> $settings the block's `settings`
     * @param list<array{id: string, style: array<string,mixed>}> $classDefinitions ordered style classes
     * @return list<string>
     */
    public function classesFor(
        array $settings,
        StyleTargets $targets,
        string $target,
        array $classDefinitions = [],
    ): array {
        $classes = [];
        if ($targets->isPart($target)) {
            // A part's own record and capabilities (a links block's links); the block's style
            // classes are the block's, and never reach a part.
            $parts = is_array($settings['parts'] ?? null) ? $settings['parts'] : [];
            $instance = is_array($parts[$target] ?? null) ? $parts[$target] : [];
            $paths = array_values(array_filter(
                array_keys(StyleSchema::properties()),
                static fn (string $path): bool => $targets->partCapabilities($target)->allows($path),
            ));
            $classDefinitions = [];
        } else {
            $instance = is_array($settings['style'] ?? null) ? $settings['style'] : [];
            $paths = $targets->stylePathsFor($target);
        }
        foreach ($paths as $path) {
            $def = StyleSchema::property($path);
            if ($def === null) {
                continue;
            }
            $resolved = $this->resolver->resolve($path, $classDefinitions, $instance, $def);
            // Layout is emitted RESOLVED: a class at every breakpoint the property resolves to a
            // value or a reset, not only where it is declared (container-layout spec §3.3). The
            // compiled rules for dormancy and for span pairing read the parent's mode and track
            // count at each breakpoint, so a breakpoint that inherited its state must still carry
            // a class. Every other property keeps exact-only emission: its cascade is the CSS
            // media order.
            $resolvedEmission = self::emitsResolved($path);
            foreach ($resolved as $breakpoint => $resolution) {
                if (!$resolution->exact && !($resolvedEmission && $resolution->state !== 'theme-default')) {
                    continue;
                }
                $classes[] = $resolution->state === 'reset'
                    ? ClassNames::reset($path, $breakpoint)
                    : ClassNames::for($path, (string) ($resolution->value['value'] ?? ''), $breakpoint);
            }
            // A container that declares no track count still needs a track class at every
            // breakpoint: a span pairs with it, and `auto` is the default track state (one track).
            if ($path === 'layout.columns') {
                foreach ($resolved as $breakpoint => $resolution) {
                    if ($resolution->state === 'theme-default') {
                        $classes[] = ClassNames::for($path, 'auto', $breakpoint);
                    }
                }
            }
        }
        if (in_array('advanced.css_classes', $targets->advancedPathsFor($target), true)) {
            foreach ((array) ($settings['advanced']['css_classes'] ?? []) as $name) {
                if (is_string($name) && $name !== '') {
                    $classes[] = $name;
                }
            }
        }
        return $classes;
    }

    /** Which properties are emitted at every breakpoint they resolve (spec §3.3). */
    private static function emitsResolved(string $path): bool
    {
        $def = StyleSchema::property($path);
        return $def !== null && ($def->group === 'layout' || $def->group === 'layout.item'
            || $path === 'alignment.content');
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string, string> attribute name => raw value (unescaped), in emission order
     */
    public function attrsFor(array $settings, StyleTargets $targets, string $target): array
    {
        $attrs = [];
        $advanced = is_array($settings['advanced'] ?? null) ? $settings['advanced'] : [];
        foreach ($targets->advancedPathsFor($target) as $path) {
            switch ($path) {
                case 'advanced.anchor':
                    if (is_string($advanced['anchor'] ?? null) && $advanced['anchor'] !== '') {
                        $attrs['id'] = $advanced['anchor'];
                    }
                    break;
                case 'advanced.attributes':
                    foreach ((array) ($advanced['attributes'] ?? []) as $name => $value) {
                        if (is_string($name) && str_starts_with($name, 'data-') && is_scalar($value)) {
                            $attrs[$name] = (string) $value;
                        }
                    }
                    break;
                case 'advanced.accessibility.label':
                    $label = $advanced['accessibility']['label'] ?? null;
                    if (is_string($label) && $label !== '') {
                        $attrs['aria-label'] = $label;
                    }
                    break;
            }
        }
        return $attrs;
    }
}
