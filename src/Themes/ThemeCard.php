<?php

declare(strict_types=1);

namespace Thallo\Render\Themes;

/**
 * What a theme says about itself, for the admin's theme gallery: read from the optional keys of
 * its `theme.json` — `title`, `version`, `description`, `author`, `tags`, `screenshot`, `colors`.
 *
 * None of it is required and none of it can break a theme. Whether a theme is selectable is the
 * validator's question (its templates, its vocabulary, its stylesheets); this only describes one,
 * so a wrong value is left out of the card rather than refused. The values are shown to an
 * operator and the screenshot is served to a browser, so each is held to a shape: plain bounded
 * strings, a handful of short tags, hex colours, and an image file INSIDE the theme's directory.
 */
final class ThemeCard
{
    public const MAX_DESCRIPTION = 300;
    public const MAX_SCREENSHOT_BYTES = 2_000_000;
    public const SCREENSHOT_ROUTE = '/_thallo/theme-screenshot';
    /** extension → content type. No SVG: it is served from the site's origin and can carry script. */
    public const SCREENSHOT_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];
    private const MAX_TAGS = 6;
    private const COLORS = ['background', 'text', 'accent'];

    /**
     * @param list<string> $tags
     * @param array<string,string>|null $colors
     */
    private function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly ?string $version,
        private readonly ?string $description,
        private readonly ?string $author,
        private readonly array $tags,
        private readonly ?array $colors,
        private readonly ?string $screenshotFile,
    ) {
    }

    public static function fromDir(string $name, string $dir): self
    {
        $dir = rtrim($dir, '/');
        $manifest = is_file($dir . '/theme.json')
            ? json_decode((string) file_get_contents($dir . '/theme.json'), true)
            : null;
        $manifest = is_array($manifest) ? $manifest : [];

        return new self(
            $name,
            self::text($manifest['title'] ?? null, 80) ?? $name,
            self::text($manifest['version'] ?? null, 32),
            self::text($manifest['description'] ?? null, self::MAX_DESCRIPTION),
            self::text($manifest['author'] ?? null, 80),
            self::tags($manifest['tags'] ?? null),
            self::colors($manifest['colors'] ?? null),
            self::screenshot($dir, $manifest['screenshot'] ?? null),
        );
    }

    /** The screenshot's absolute path, or null: the only file of a theme this card ever names. */
    public function screenshotFile(): ?string
    {
        return $this->screenshotFile;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'version' => $this->version,
            'description' => $this->description,
            'author' => $this->author,
            'tags' => $this->tags,
            'colors' => $this->colors,
            // Versioned by the file's own mtime, so the image caches hard and a new one is a new URL.
            'screenshot_url' => $this->screenshotFile === null
                ? null
                : self::SCREENSHOT_ROUTE . '/' . rawurlencode($this->name)
                    . '?v=' . (int) @filemtime($this->screenshotFile),
        ];
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** @return list<string> */
    private static function tags(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $tag) {
            $tag = is_string($tag) ? mb_strtolower(trim($tag)) : '';
            if (preg_match('/\A[\p{L}\p{N}][\p{L}\p{N} -]{0,23}\z/u', $tag) === 1 && !in_array($tag, $out, true)) {
                $out[] = $tag;
            }
            if (count($out) === self::MAX_TAGS) {
                break;
            }
        }
        return $out;
    }

    /** @return array<string,string>|null */
    private static function colors(mixed $value): ?array
    {
        $out = [];
        foreach (self::COLORS as $role) {
            $color = is_array($value) ? ($value[$role] ?? null) : null;
            if (is_string($color) && preg_match('/\A#(?:[0-9a-f]{3}|[0-9a-f]{6})\z/i', $color) === 1) {
                $out[$role] = strtolower($color);
            }
        }
        return $out === [] ? null : $out;
    }

    private static function screenshot(string $dir, mixed $named): ?string
    {
        $candidates = is_string($named)
            ? [$named]
            : array_map(static fn (string $ext): string => 'screenshot.' . $ext, array_keys(self::SCREENSHOT_TYPES));
        // Path-safe segments only: no leading slash, no `..`, an image extension.
        $shape = '~\A[a-z0-9][a-z0-9_-]*(?:[./][a-z0-9_-]+)*\.(?:'
            . implode('|', array_keys(self::SCREENSHOT_TYPES)) . ')\z~i';
        foreach ($candidates as $relative) {
            if (preg_match($shape, $relative) !== 1 || str_contains($relative, '..')) {
                continue;
            }
            $file = $dir . '/' . $relative;
            $real = realpath($file);
            $root = realpath($dir);
            // realpath: a symlink out of the theme is not inside the theme.
            if ($real === false || $root === false || !str_starts_with($real, $root . '/') || !is_file($real)) {
                continue;
            }
            if (filesize($real) > self::MAX_SCREENSHOT_BYTES) {
                continue;
            }
            return $file;
        }
        return null;
    }
}
