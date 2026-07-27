<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

use Thallo\Contracts\Delivery\PreviewThemeValidator;

/**
 * Scaffolds a new app theme by copying an existing one (clone-theme pins):
 *
 *   - new name grammar: lowercase [a-z0-9][a-z0-9_-]* and never 'default'
 *     (path-safe by construction — the name becomes a filesystem directory
 *     and later a URL query value);
 *   - refuses to overwrite: an existing themes/{name}/ is a hard error;
 *   - writability failures are LOUD RuntimeExceptions with the path in the
 *     message (read-only production containers surface a clear 4xx/CLI error,
 *     never a partial copy);
 *   - theme.json's "name" is rewritten to the new theme name.
 *
 * Trust tier: templates.manage — the same operator surface as template
 * editing (the caller gates; this class only enforces the file rules).
 */
final class ThemeCloner
{
    public function __construct(
        private readonly string $appThemesDir,
        private readonly string $packThemesDir,
        private readonly PreviewThemeValidator $validator,
    ) {
    }

    /** @return array{name:string,path:string} */
    public function clone(string $newName, string $from = 'default'): array
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $newName) !== 1) {
            throw new \InvalidArgumentException(
                'Theme name must be lowercase letters/digits with dashes or underscores.',
            );
        }
        if ($newName === 'default') {
            throw new \InvalidArgumentException("'default' is the pack theme — pick another name.");
        }

        $source = $this->sourceDir($from);
        $themesDir = rtrim($this->appThemesDir, '/');
        $dest = $themesDir . '/' . $newName;
        if (is_dir($dest)) {
            throw new \RuntimeException("Theme '{$newName}' already exists at {$dest}.");
        }
        if (!is_dir($themesDir) && !@mkdir($themesDir, 0755, true)) {
            throw new \RuntimeException("Cannot create the themes directory ({$themesDir}) — is the app dir writable?");
        }
        if (!is_writable($themesDir)) {
            throw new \RuntimeException("The themes directory ({$themesDir}) is not writable by the server.");
        }

        $this->copyDir($source, $dest);
        $this->rewriteThemeName($dest . '/theme.json', $newName);

        return ['name' => $newName, 'path' => $dest];
    }

    private function sourceDir(string $from): string
    {
        if ($from === 'default') {
            return $this->packThemesDir . '/default';
        }
        if (!$this->validator->isValidTheme($from)) {
            throw new \InvalidArgumentException("Unknown source theme '{$from}'.");
        }
        return rtrim($this->appThemesDir, '/') . '/' . $from;
    }

    private function copyDir(string $src, string $dest): void
    {
        if (!@mkdir($dest, 0755, true)) {
            throw new \RuntimeException("Cannot create {$dest}.");
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($src))), '/');
            $target = $dest . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0755, true)) {
                    throw new \RuntimeException("Cannot create {$target}.");
                }
            } elseif (!@copy($item->getPathname(), $target)) {
                throw new \RuntimeException("Cannot copy to {$target}.");
            }
        }
    }

    private function rewriteThemeName(string $themeJsonPath, string $newName): void
    {
        $decoded = is_file($themeJsonPath)
            ? json_decode((string) file_get_contents($themeJsonPath), true)
            : null;
        $config = is_array($decoded) ? $decoded : [];
        $config['name'] = $newName;
        file_put_contents(
            $themeJsonPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }
}
