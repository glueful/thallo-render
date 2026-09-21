<?php

declare(strict_types=1);

namespace Thallo\Render\Themes;

use Thallo\Contracts\Delivery\PreviewThemeValidator;

/**
 * The themes an operator may switch to, each with its {@see ThemeCard}: the pack's `default`
 * first, then every app `themes/{name}` directory the validator accepts — the same set and order
 * as the switcher always offered. A directory that is not a loadable theme has no card, and so no
 * screenshot the site would serve.
 */
final class ThemeGallery
{
    public function __construct(
        private readonly string $appThemesDir,
        private readonly string $packThemesDir,
        private readonly PreviewThemeValidator $validator,
    ) {
    }

    /** @return list<ThemeCard> */
    public function cards(): array
    {
        $cards = [ThemeCard::fromDir('default', $this->dirOf('default'))];
        $dir = rtrim($this->appThemesDir, '/');
        $names = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && $entry !== 'default' && is_dir($dir . '/' . $entry)) {
                $names[] = $entry;
            }
        }
        sort($names);
        foreach ($names as $name) {
            if ($this->validator->isValidTheme($name)) {
                $cards[] = ThemeCard::fromDir($name, $this->dirOf($name));
            }
        }
        return $cards;
    }

    /** One selectable theme's card; null for anything the validator does not accept. */
    public function card(string $name): ?ThemeCard
    {
        return $this->validator->isValidTheme($name) ? ThemeCard::fromDir($name, $this->dirOf($name)) : null;
    }

    private function dirOf(string $name): string
    {
        return $name === 'default'
            ? rtrim($this->packThemesDir, '/') . '/default'
            : rtrim($this->appThemesDir, '/') . '/' . $name;
    }
}
