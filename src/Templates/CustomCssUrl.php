<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

use Thallo\Render\ThemeLocator;

/**
 * The custom_css() backing (custom-css spec §4): resolves the ACTIVE theme's
 * DB-backed custom.css row to its versioned public URL, or null when absent or
 * trim-empty (the layout emits nothing). Pack-internal furniture like IconSet —
 * no app-side contract; the version uuid in the URL is the cache-buster that
 * makes the route's immutable caching safe.
 */
final class CustomCssUrl
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly ThemeLocator $theme,
    ) {
    }

    public function url(): ?string
    {
        $row = $this->templates->findCurrentSource($this->theme->activePaths()['name'], 'custom.css');
        if ($row === null || trim((string) $row['source']) === '') {
            return null;
        }
        return '/custom.css?v=' . $row['version_uuid'];
    }
}
