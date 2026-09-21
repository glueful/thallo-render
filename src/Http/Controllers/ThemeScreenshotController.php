<?php

declare(strict_types=1);

namespace Thallo\Render\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;
use Thallo\Render\Themes\ThemeCard;
use Thallo\Render\Themes\ThemeGallery;

/**
 * `GET /_thallo/theme-screenshot/{theme}`: a selectable theme's gallery screenshot, for the
 * admin's theme cards. Public, because an `<img>` cannot carry the admin's token — and a theme's
 * screenshot is a picture of a public site. It is the ONE file of a theme this serves: `{theme}`
 * is looked up in the gallery (the validator's set), and the path comes from that theme's
 * {@see ThemeCard}, which only ever names an image inside the theme — the request names no file.
 *
 * Under /_thallo/ with every other PHP-served asset (docs/production.md). The card's URL carries
 * the file's mtime, so the bytes cache as immutable and a new screenshot is a new URL.
 */
final class ThemeScreenshotController
{
    public function __construct(private readonly ThemeGallery $gallery)
    {
    }

    #[\Glueful\Routing\Attributes\ApiOperation(
        summary: 'A selectable theme\'s gallery screenshot (an image, not an API endpoint)',
        tags: ['Default'],
    )]
    public function serve(string $theme): Response
    {
        $file = $this->gallery->card($theme)?->screenshotFile();
        $type = $file === null
            ? null
            : (ThemeCard::SCREENSHOT_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null);
        if ($file === null || $type === null) {
            return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new Response((string) file_get_contents($file), 200, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
