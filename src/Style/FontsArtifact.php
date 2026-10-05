<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Fonts\FontLibrarySnapshotView;
use Thallo\Contracts\Style\FontStacks;

/**
 * The workspace's fonts stylesheet (block typeface spec §3.4): for every current uploaded family, an
 * `@font-face` per servable face and its utility in `@layer settings`. Only the family's ID reaches
 * CSS (`"thallo-font-<id>"`, `.t-font-<id>`) — never its display name — and its stack is the generated
 * family, then the fallback's named stack ending with its generic. An uploaded family never has bold
 * faked (`font-synthesis: style`). A face the reader could not parse keeps the compatibility
 * declaration (`font-weight: 100 900`, normal style). Deterministic: the hash is over the bytes.
 */
final class FontsArtifact
{
    public static function compile(FontLibrarySnapshotView $snapshot): string
    {
        $faces = '';
        $utilities = '';
        foreach ($snapshot->active() as $family) {
            $name = 'thallo-font-' . $family->id;
            foreach ($family->faces as $face) {
                if ($face['url'] === '') {
                    continue; // not publicly servable: the stack's next family renders
                }
                [$weight, $style] = $face['unknown']
                    ? ['100 900', 'normal']
                    : [
                        $face['weight_min'] === $face['weight_max']
                            ? (string) $face['weight_min']
                            : $face['weight_min'] . ' ' . $face['weight_max'],
                        $face['italic'] ? 'italic' : 'normal',
                    ];
                $faces .= '@font-face{font-family:"' . $name . '";src:url("' . self::cssString($face['url'])
                    . '") format("woff2");font-weight:' . $weight . ';font-style:' . $style
                    . ";font-display:swap}\n";
            }
            $utilities .= ClassNames::selector(ClassNames::forFont($family->id)) . '{font-family:"' . $name . '",'
                . FontStacks::forFallback($family->fallback) . ';font-synthesis:style}';
        }
        return $utilities === '' ? '' : $faces . '@layer settings{' . $utilities . "}\n";
    }

    /** 16 hex characters over the compiled bytes. */
    public static function hash(FontLibrarySnapshotView $snapshot): string
    {
        return substr(hash('sha256', self::compile($snapshot)), 0, 16);
    }

    /** A CSS string's contents: quotes, backslashes, controls and `<` as hex escapes. */
    private static function cssString(string $value): string
    {
        return preg_replace_callback(
            '/[\x00-\x1F\x7F"\'\\\\<>]/',
            static fn (array $m): string => sprintf('\\%x ', ord($m[0][0])),
            $value,
        ) ?? '';
    }
}
