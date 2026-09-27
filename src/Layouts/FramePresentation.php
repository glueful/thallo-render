<?php

declare(strict_types=1);

namespace Thallo\Render\Layouts;

/**
 * A layout's Frame (type layouts spec §6.4) over a page that has no theme presentation of its own —
 * the shop's product page, which renders centered with the default header and footer. The shop's
 * renderer and the layout stage both compose it here, so the stage shows what the site serves.
 * Anything but the exact frame values degrades to the default, as the entry pages' composition does.
 */
final class FramePresentation
{
    /**
     * @param array<string,mixed>|null $frame the layout's Frame settings (width, header, footer)
     * @return array{show_title: bool, layout: string, header: string, footer: string, style_classes: string}
     */
    public static function fixed(?array $frame): array
    {
        return [
            'show_title' => true,
            'layout' => ($frame['width'] ?? null) === 'full' ? 'full' : 'centered',
            'header' => ($frame['header'] ?? null) === 'hidden' ? 'hidden' : 'default',
            'footer' => ($frame['footer'] ?? null) === 'hidden' ? 'hidden' : 'default',
            'style_classes' => '',
        ];
    }
}
