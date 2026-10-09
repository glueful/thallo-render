<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Style\Palette;
use Thallo\Contracts\Style\StyleSchema;

/**
 * Custom palette spec §3.2: a colour value naming an unconfigured brand slot (or its contrast
 * token) is removed from a cascade layer before the cascade resolves, so the layer behaves as if
 * it never set the property and a lower layer — or the theme default — shows through. CSS cannot
 * do this: an undefined variable resolves to the inherited or initial value, never to an earlier
 * declaration, so a hover colour naming a cleared slot would replace the resting colour.
 */
final class PaletteAvailability
{
    /**
     * The style record without the unavailable values at one property path.
     *
     * @param array<string,mixed> $style
     * @return array<string,mixed>
     */
    public static function strip(array $style, string $path, Palette $palette): array
    {
        $parts = explode('.', $path);
        $last = (string) array_pop($parts);
        $node = &$style;
        foreach ($parts as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                return $style;
            }
            $node = &$node[$part];
        }
        if (!isset($node[$last]) || !is_array($node[$last])) {
            return $style;
        }
        if (isset($node[$last]['type'])) {
            if (self::unavailable($node[$last], $palette)) {
                unset($node[$last]);
            }
            return $style;
        }
        foreach (StyleSchema::BREAKPOINTS as $bp) {
            $value = $node[$last][$bp] ?? null;
            if (is_array($value) && self::unavailable($value, $palette)) {
                unset($node[$last][$bp]);
            }
        }
        return $style;
    }

    /** @param array<string,mixed> $typed */
    private static function unavailable(array $typed, Palette $palette): bool
    {
        return ($typed['type'] ?? null) === 'token' && is_string($typed['value'] ?? null)
            && $palette->isUnavailable($typed['value']);
    }
}
