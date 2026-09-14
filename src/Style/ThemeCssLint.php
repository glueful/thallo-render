<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

/**
 * What a theme stylesheet may not contain once Thallo delivers it inside `@layer theme`
 * (visual builder spec §2.2, §2.3): stylesheet-level constructs the layered artifact cannot
 * place (`@import`, `@charset`, `@namespace`), and `!important` on a managed property in a
 * rule that targets a block, which would invert the layer order against managed settings.
 * Property-aware on purpose: `!important` elsewhere in a theme is not this lint's concern.
 */
final class ThemeCssLint
{
    /** CSS properties the managed settings write (spec §1.3). */
    public const MANAGED_PROPERTIES = [
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'padding-block', 'padding-inline',
        'margin', 'margin-top', 'margin-bottom', 'margin-block',
        'max-width', 'width',
        'text-align', 'justify-content', 'margin-inline',
        'font-size', 'font-weight',
        'display',
        'box-shadow',
        'border-radius',
        'background-color', 'background', 'color', 'border-color', 'border-width', 'border-style', 'border',
    ];

    /**
     * @return list<string> messages, `file:line: …`; empty when clean
     */
    public static function lint(string $css, string $file): array
    {
        $errors = [];
        foreach (['@import', '@charset', '@namespace'] as $construct) {
            $offset = 0;
            while (($pos = stripos($css, $construct, $offset)) !== false) {
                $line = substr_count(substr($css, 0, $pos), "\n") + 1;
                $errors[] = "{$file}:{$line}: {$construct} cannot be delivered inside @layer theme; "
                    . 'declare the stylesheet in theme.json instead';
                $offset = $pos + strlen($construct);
            }
        }

        // Rules: selector { declarations }. Comments are stripped first; nested at-rules
        // (media queries) leave their inner rules matchable because we scan for `{...}` pairs
        // whose selector text contains .thallo-block.
        $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        if (preg_match_all('~([^{}]+)\{([^{}]*)\}~', $stripped, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $i => [$selector, $selectorOffset]) {
                if (!str_contains($selector, '.thallo-block')) {
                    continue;
                }
                $declarations = $matches[2][$i][0];
                foreach (explode(';', $declarations) as $declaration) {
                    if (stripos($declaration, '!important') === false) {
                        continue;
                    }
                    $property = strtolower(trim((string) strstr($declaration, ':', true)));
                    if (in_array($property, self::MANAGED_PROPERTIES, true)) {
                        $line = substr_count(substr($stripped, 0, $selectorOffset), "\n") + 1;
                        $errors[] = "{$file}:{$line}: !important on managed property {$property} in a block rule "
                            . '(' . trim($selector) . ') would defeat managed settings';
                    }
                }
            }
        }
        return $errors;
    }
}
