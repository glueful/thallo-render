<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

/**
 * The merged template listing (spec §6): pack-default files + app-theme files + active
 * DB rows, with per-path origin (db > theme > default — loader precedence). Also reads
 * a FILESYSTEM source (theme file first, pack default second) as the editor's
 * copy-from-disk starting point.
 */
final class TemplateCatalog
{
    public function __construct(
        private readonly TemplateRepository $repo,
        private readonly string $appThemesDir,
        private readonly string $packThemesDir,
    ) {
    }

    /** @return list<array{path:string,origin:string,overridden:bool,updated_at:?string}> */
    public function list(string $theme): array
    {
        $files = [];
        foreach ($this->walk($this->packThemesDir . '/default/templates') as $p) {
            $files[$p] = 'default';
        }
        if ($theme !== 'default') {
            foreach ($this->walk(rtrim($this->appThemesDir, '/') . '/' . $theme . '/templates') as $p) {
                $files[$p] = 'theme';
            }
        }
        $rows = $this->repo->listActive($theme);

        $paths = array_unique([...array_keys($files), ...array_keys($rows)]);
        sort($paths);
        $out = [];
        foreach ($paths as $p) {
            $out[] = [
                'path' => $p,
                'origin' => isset($rows[$p]) ? 'db' : $files[$p],
                'overridden' => isset($rows[$p]),
                'updated_at' => isset($rows[$p]) && $rows[$p] !== '' ? $rows[$p] : null,
            ];
        }
        return $out;
    }

    /** @return array{source:string,origin:string}|null filesystem read (db handled by caller) */
    public function readFile(string $theme, string $path): ?array
    {
        if ($theme !== 'default') {
            $themeFile = rtrim($this->appThemesDir, '/') . '/' . $theme . '/templates/' . $path;
            if (is_file($themeFile)) {
                return ['source' => (string) file_get_contents($themeFile), 'origin' => 'theme'];
            }
        }
        $default = $this->packThemesDir . '/default/templates/' . $path;
        if (is_file($default)) {
            return ['source' => (string) file_get_contents($default), 'origin' => 'default'];
        }
        return null;
    }

    /**
     * READ-ONLY theme files for the admin browser (custom-css follow-up):
     * the theme's asset stylesheets/scripts and theme.json — viewable so
     * operators can find class names to override in custom.css, never
     * editable (no DB layer serves them; the save grammar rejects them).
     *
     * @return list<array{path:string,origin:string}>
     */
    public function listReadOnly(string $theme): array
    {
        $files = [];
        foreach ($this->walkAssets($this->packThemesDir . '/default') as $p) {
            $files[$p] = 'default';
        }
        if (is_file($this->packThemesDir . '/default/theme.json')) {
            $files['theme.json'] = 'default';
        }
        if ($theme !== 'default') {
            $themeRoot = rtrim($this->appThemesDir, '/') . '/' . $theme;
            foreach ($this->walkAssets($themeRoot) as $p) {
                $files[$p] = 'theme';
            }
            if (is_file($themeRoot . '/theme.json')) {
                $files['theme.json'] = 'theme';
            }
        }
        ksort($files);
        $out = [];
        foreach ($files as $path => $origin) {
            $out[] = ['path' => $path, 'origin' => $origin];
        }
        return $out;
    }

    /**
     * Read one read-only theme file (theme-root-relative path, already
     * grammar-validated by the caller): app theme first, pack default second
     * — the same ladder readFile() uses for templates.
     *
     * @return array{source:string,origin:string}|null
     */
    public function readReadOnlyFile(string $theme, string $path): ?array
    {
        if ($theme !== 'default') {
            $themeFile = rtrim($this->appThemesDir, '/') . '/' . $theme . '/' . $path;
            if (is_file($themeFile)) {
                return ['source' => (string) file_get_contents($themeFile), 'origin' => 'theme'];
            }
        }
        $default = $this->packThemesDir . '/default/' . $path;
        if (is_file($default)) {
            return ['source' => (string) file_get_contents($default), 'origin' => 'default'];
        }
        return null;
    }

    /** @return list<string> theme-root-relative assets/… paths (.css/.js) under $themeRoot */
    private function walkAssets(string $themeRoot): array
    {
        $dir = $themeRoot . '/assets';
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            $name = $file->getFilename();
            if ($file->isFile() && (str_ends_with($name, '.css') || str_ends_with($name, '.js'))) {
                $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/');
                $out[] = 'assets/' . $rel;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * App-theme DIRECTORY candidates for the theme switcher — names only;
     * the caller validates each via PreviewThemeValidator (single source of
     * theme-validity truth; no duplicate rules here).
     *
     * @return list<string>
     */
    public function themeCandidates(): array
    {
        $dir = rtrim($this->appThemesDir, '/');
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($dir . '/' . $entry)) {
                $out[] = $entry;
            }
        }
        sort($out);
        return $out;
    }

    /** @return list<string> theme-relative *.twig paths under $dir */
    private function walk(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                $out[] = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/');
            }
        }
        sort($out);
        return $out;
    }
}
