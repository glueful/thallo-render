<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Style\FontStacks;
use Thallo\Contracts\Style\Vocabulary;
use Thallo\Render\ThemeConfigError;

/**
 * A theme's mapping of the platform vocabulary to CSS values (visual builder spec §2.2), read
 * from `theme.json`: `vocabulary` maps every baseline name to a CSS value (a `var()` reference
 * is fine), and `stylesheets` lists the theme's selector-bearing CSS files, which Thallo delivers
 * inside `@layer theme`. Extensions are out of v1: an unknown token is an error, so no document
 * can reference a name a theme lacks.
 */
final class ThemeVocabulary
{
    /**
     * @param array<string,string> $values token => CSS value, every baseline name present
     * @param list<string> $stylesheets theme-relative paths, manifest order
     * @param array{family: string, stack: string, files: list<array{src: string, weight: string,
     *     style: string}>}|null $face the theme's declared face, when it declares a usable one
     */
    private function __construct(
        public readonly string $theme,
        private readonly array $values,
        private readonly array $stylesheets,
        private readonly ?array $face = null,
        /** @var list<string> site-controlled tokens the theme tried to map (custom palette spec §3.1) */
        private readonly array $ignored = [],
    ) {
    }

    /** @return list<string> the site-controlled tokens this theme's manifest mapped, ignored */
    public function ignored(): array
    {
        return $this->ignored;
    }

    /**
     * @param array<string,mixed> $json the decoded theme.json
     * @param string $themeDir the theme directory, for stylesheet existence checks
     * @throws ThemeConfigError naming every missing baseline token, the first unknown token, a
     *   non-string value, a missing stylesheet, or an absent manifest
     */
    public static function fromThemeJson(array $json, string $themeDir): self
    {
        $name = is_string($json['name'] ?? null) ? $json['name'] : basename($themeDir);
        $vocabulary = $json['vocabulary'] ?? null;
        if (!is_array($vocabulary)) {
            throw new ThemeConfigError(
                "theme \"{$name}\" vocabulary is missing " . implode(', ', Vocabulary::all()),
            );
        }
        $vocabulary += Vocabulary::LITERAL_DEFAULTS; // a theme's own mapping wins; the literal fills a gap
        // The brand colours are the site's (custom palette spec §3.1): a theme's mapping is ignored.
        $ignored = array_values(array_intersect(array_keys(Vocabulary::SITE_CONTROLLED), array_keys($vocabulary)));
        $vocabulary = Vocabulary::SITE_CONTROLLED + $vocabulary;
        $missing = [];
        foreach (Vocabulary::all() as $token) {
            if (!array_key_exists($token, $vocabulary)) {
                $missing[] = $token;
            }
        }
        if ($missing !== []) {
            throw new ThemeConfigError("theme \"{$name}\" vocabulary is missing " . implode(', ', $missing));
        }
        $values = [];
        foreach ($vocabulary as $token => $value) {
            $token = (string) $token;
            if (!Vocabulary::isBaseline($token)) {
                throw new ThemeConfigError("theme \"{$name}\" vocabulary declares unknown token {$token}");
            }
            $usable = is_string($value) && trim($value) !== ''
                && !str_contains($value, ';') && !str_contains($value, '}');
            if (!$usable) {
                throw new ThemeConfigError(
                    "theme \"{$name}\" vocabulary value for {$token} must be a CSS value string",
                );
            }
            $values[$token] = trim($value);
        }

        $stylesheets = $json['stylesheets'] ?? null;
        if (!is_array($stylesheets) || !array_is_list($stylesheets) || $stylesheets === []) {
            throw new ThemeConfigError("theme \"{$name}\" declares no stylesheets");
        }
        foreach ($stylesheets as $sheet) {
            if (!is_string($sheet) || $sheet === '' || str_contains($sheet, '..') || str_starts_with($sheet, '/')) {
                throw new ThemeConfigError("theme \"{$name}\" stylesheet entries must be theme-relative paths");
            }
            if (!is_file($themeDir . '/' . $sheet)) {
                throw new ThemeConfigError("theme \"{$name}\" stylesheet {$sheet} does not exist");
            }
        }

        return new self($name, $values, array_values($stylesheets), self::parseFace($json['face'] ?? null), $ignored);
    }

    /**
     * The optional `face` (block typeface spec §2.2): the theme's own typeface — its family name, the
     * stack Theme resolves to, and its files (the admin's specimen). Metadata, never an error: anything
     * unusable leaves the theme without a face (Theme is then the system stack), and an unusable file
     * entry is dropped.
     *
     * @return array{family: string, stack: string, files: list<array{src: string, weight: string,
     *     style: string}>}|null
     */
    private static function parseFace(mixed $face): ?array
    {
        if (!is_array($face)) {
            return null;
        }
        $family = $face['family'] ?? null;
        $stack = $face['stack'] ?? null;
        $cssSafe = static fn (mixed $v): bool => is_string($v) && trim($v) !== ''
            && preg_match('/[;{}<>\\\\]/', $v) !== 1;
        if (!$cssSafe($family) || str_contains((string) $family, '"') || !$cssSafe($stack)) {
            return null;
        }
        $files = [];
        foreach (is_array($face['files'] ?? null) ? $face['files'] : [] as $file) {
            $src = is_array($file) ? ($file['src'] ?? null) : null;
            $weight = is_array($file) ? ($file['weight'] ?? null) : null;
            $style = is_array($file) ? ($file['style'] ?? null) : null;
            $usable = is_string($src) && preg_match('#\A[A-Za-z0-9._/-]+\.woff2\z#', $src) === 1
                && !str_contains($src, '..') && !str_starts_with($src, '/')
                && is_string($weight) && preg_match('/\A\d{1,4}( \d{1,4})?\z/', $weight) === 1
                && in_array($style, ['normal', 'italic'], true);
            if ($usable) {
                $files[] = ['src' => $src, 'weight' => $weight, 'style' => $style];
            }
        }
        return ['family' => trim((string) $family), 'stack' => trim((string) $stack), 'files' => $files];
    }

    /**
     * @return array{family: string, stack: string, files: list<array{src: string, weight: string,
     *     style: string}>}|null
     */
    public function face(): ?array
    {
        return $this->face;
    }

    /** What the Theme typeface resolves to: the declared face's stack, or the system stack. */
    public function themeFaceStack(): string
    {
        return $this->face['stack'] ?? FontStacks::SYSTEM;
    }

    /** The CSS value for a baseline token. */
    public function value(string $token): string
    {
        return $this->values[$token] ?? throw new \InvalidArgumentException("unknown token \"{$token}\"");
    }

    /** @return array<string,string> token => CSS value, contract order */
    public function values(): array
    {
        $ordered = [];
        foreach (Vocabulary::all() as $token) {
            $ordered[$token] = $this->values[$token];
        }
        return $ordered;
    }

    /** @return list<string> */
    public function stylesheets(): array
    {
        return $this->stylesheets;
    }
}
