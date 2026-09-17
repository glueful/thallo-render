<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Style\PageStyleCapabilities;
use Thallo\Contracts\Style\StyleTargets;

/**
 * The page's own style frame (`_presentation.style`): the Page tab's padding, margin and
 * background, stored in a block's style shape and painted with a block's utility classes on
 * the page's <main>. The page's capabilities are PageStyleCapabilities, shared with the
 * validator.
 */
final class PageStyle
{
    /**
     * The utility classes for a page style, space-joined; '' when none is set.
     *
     * @param array<string,mixed>|null $style
     */
    public static function classes(?array $style, ?BlockStyleEmitter $emitter = null): string
    {
        if ($style === null || $style === []) {
            return '';
        }
        $targets = StyleTargets::fromDeclaration(StyleTargets::root('box', PageStyleCapabilities::PATHS));
        $classes = ($emitter ?? new BlockStyleEmitter())->classesFor(['style' => $style], $targets, 'root');
        return implode(' ', $classes);
    }
}
