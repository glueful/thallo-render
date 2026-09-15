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
        $instance = is_array($settings['style'] ?? null) ? $settings['style'] : [];
        foreach ($targets->stylePathsFor($target) as $path) {
            $def = StyleSchema::property($path);
            if ($def === null) {
                continue;
            }
            $resolved = $this->resolver->resolve($path, $classDefinitions, $instance, $def);
            foreach ($resolved as $breakpoint => $resolution) {
                if (!$resolution->exact) {
                    continue;
                }
                $classes[] = $resolution->state === 'reset'
                    ? ClassNames::reset($path, $breakpoint)
                    : ClassNames::for($path, (string) ($resolution->value['value'] ?? ''), $breakpoint);
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
