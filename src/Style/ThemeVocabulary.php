<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

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
     */
    private function __construct(
        public readonly string $theme,
        private readonly array $values,
        private readonly array $stylesheets,
    ) {
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

        return new self($name, $values, array_values($stylesheets));
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
