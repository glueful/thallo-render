<?php

declare(strict_types=1);

namespace Thallo\Render;

/**
 * Resolves the active theme's filesystem paths per the spec §4 ladder:
 *   1. app theme dir missing entirely → pack default (a warning is the caller's job)
 *   2. app theme present but invalid theme.json → ThemeConfigError (loud 500)
 *   3. pack default missing/invalid → RuntimeException (broken install, hard 500)
 *   4. per-TEMPLATE fallback happens in the Twig loader: activePaths() returns the app
 *      theme first and the pack default second, so a theme may omit any template.
 * Resolution happens at construction (boot) — v1 theme changes require a restart.
 */
final class ThemeLocator
{
    /** @var array{templates: list<string>, assets: string, name: string} */
    private array $active;

    /** @var array<string,mixed> validated theme.json `settings` block (may be empty) */
    private array $settings = [];

    public function __construct(string $themeName, string $appThemesDir, ?string $packThemesDir = null)
    {
        $packThemesDir ??= dirname(__DIR__) . '/themes';
        $default = $packThemesDir . '/default';
        if (!is_dir($default . '/templates') || $this->readThemeJson($default) === null) {
            throw new \RuntimeException(
                'The thallo-render default theme is missing or invalid — broken install.',
            );
        }

        $appTheme = rtrim($appThemesDir, '/') . '/' . $themeName;
        $templates = [];
        $assets = $default . '/assets';
        $name = 'default';
        $json = $this->readThemeJson($default);

        if ($themeName !== 'default' && is_dir($appTheme)) {
            $appJson = $this->readThemeJson($appTheme);
            if ($appJson === null) {
                throw new ThemeConfigError(
                    "Theme \"{$themeName}\" has a missing or invalid theme.json ({$appTheme}/theme.json).",
                );
            }
            $templates[] = $appTheme . '/templates';
            $assets = $appTheme . '/assets';
            $name = $themeName;
            $json = $appJson;
        }
        $templates[] = $default . '/templates';

        $this->active = ['templates' => $templates, 'assets' => $assets, 'name' => $name];
        $this->settings = $this->validateSettings($json['settings'] ?? [], $name);
    }

    /**
     * The ACTIVE theme's validated `settings` block (modern-default-theme spec
     * §5a). Fixed vocabulary on purpose — presentation is a system contract:
     * top level allows `show_title` (bool), `layout` ('full'|'centered'), and
     * `types` (map of content-type slug -> the same two keys). Anything else
     * is rejected LOUDLY, same posture as an invalid theme.json.
     *
     * @return array<string,mixed>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /** @return array<string,mixed> */
    private function validateSettings(mixed $raw, string $themeName): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (!is_array($raw)) {
            throw new ThemeConfigError("Theme \"{$themeName}\": `settings` must be an object.");
        }
        $clean = $this->validateSettingPair($raw, $themeName, 'settings');
        if (isset($raw['types'])) {
            if (!is_array($raw['types'])) {
                throw new ThemeConfigError("Theme \"{$themeName}\": `settings.types` must be an object.");
            }
            $types = [];
            foreach ($raw['types'] as $slug => $typeRaw) {
                if (!is_array($typeRaw)) {
                    throw new ThemeConfigError(
                        "Theme \"{$themeName}\": `settings.types.{$slug}` must be an object.",
                    );
                }
                $types[(string) $slug] = $this->validateSettingPair($typeRaw, $themeName, "settings.types.{$slug}");
            }
            $clean['types'] = $types;
        }
        return $clean;
    }

    /**
     * @param array<mixed> $raw
     * @return array<string,mixed>
     */
    private function validateSettingPair(array $raw, string $themeName, string $path): array
    {
        $clean = [];
        foreach ($raw as $key => $value) {
            if ($key === 'types' && $path === 'settings') {
                continue; // validated by the caller
            }
            if ($key === 'show_title') {
                if (!is_bool($value)) {
                    throw new ThemeConfigError("Theme \"{$themeName}\": `{$path}.show_title` must be a boolean.");
                }
                $clean['show_title'] = $value;
            } elseif ($key === 'layout') {
                if (!in_array($value, ['full', 'centered'], true)) {
                    throw new ThemeConfigError(
                        "Theme \"{$themeName}\": `{$path}.layout` must be 'full' or 'centered'.",
                    );
                }
                $clean['layout'] = $value;
            } else {
                throw new ThemeConfigError("Theme \"{$themeName}\": unknown setting `{$path}.{$key}`.");
            }
        }
        return $clean;
    }

    /** @return array{templates: list<string>, assets: string, name: string} */
    public function activePaths(): array
    {
        return $this->active;
    }

    /** @return array<string,mixed>|null null = missing or invalid */
    private function readThemeJson(string $themeDir): ?array
    {
        $file = $themeDir . '/theme.json';
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded) || !is_string($decoded['name'] ?? null) || $decoded['name'] === '') {
            return null;
        }
        return $decoded;
    }
}
