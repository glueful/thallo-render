<?php

declare(strict_types=1);

namespace Thallo\Render;

use Thallo\Contracts\Delivery\PreviewThemeValidator;

/**
 * The render pack's theme validation (preview-sessions spec §5) — the SAME ladder
 * semantics ThemeLocator resolves with: 'default' (the pack theme) is always valid;
 * anything else must be an app themes/{name} directory with a valid theme.json.
 */
final class RenderThemeValidator implements PreviewThemeValidator
{
    public function __construct(private readonly string $appThemesDir)
    {
    }

    public function isValidTheme(string $name): bool
    {
        if ($name === 'default') {
            return true;
        }
        if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/i', $name) !== 1) {
            return false; // path-safe names only — this value ends up in filesystem paths
        }
        $dir = rtrim($this->appThemesDir, '/') . '/' . $name;
        if (!is_dir($dir . '/templates') || !is_file($dir . '/theme.json')) {
            return false;
        }
        $decoded = json_decode((string) file_get_contents($dir . '/theme.json'), true);
        return is_array($decoded) && is_string($decoded['name'] ?? null) && $decoded['name'] !== '';
    }
}
