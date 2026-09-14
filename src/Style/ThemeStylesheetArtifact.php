<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Render\ThemeConfigError;

/**
 * The layered theme artifact (visual builder spec §2.2–2.4): the active theme's manifest
 * stylesheets followed by every contributed package stylesheet, concatenated inside
 * `@layer theme { … }`, linted, and fingerprinted by content. A pure function of its inputs
 * and this class's VERSION, so the hash is a cache identity.
 */
final class ThemeStylesheetArtifact
{
    public const VERSION = 1;

    private function __construct(
        public readonly string $css,
        public readonly string $hash,
    ) {
    }

    /**
     * @param list<string> $files absolute paths of the theme's manifest stylesheets, in order
     * @param list<string> $contributed absolute paths of package stylesheets, in order
     * @throws ThemeConfigError naming the file and line of the first construct the layer cannot hold
     */
    public static function build(array $files, array $contributed = []): self
    {
        $parts = [];
        $hash = hash_init('sha256');
        hash_update($hash, 'theme-artifact/v' . self::VERSION);
        foreach ([...$files, ...$contributed] as $file) {
            if (!is_file($file)) {
                throw new ThemeConfigError("stylesheet {$file} does not exist");
            }
            $css = (string) file_get_contents($file);
            $errors = ThemeCssLint::lint($css, basename($file));
            if ($errors !== []) {
                throw new ThemeConfigError($errors[0]);
            }
            $parts[] = '/* ' . basename($file) . " */\n" . rtrim($css) . "\n";
            hash_update($hash, basename($file) . "\0" . $css . "\0");
        }
        $digest = substr(hash_final($hash), 0, 16);
        return new self("@layer theme {\n" . implode("\n", $parts) . "}\n", $digest);
    }

    /** The served file name for a hash, `theme-{hash}.css`, or null when it is not one. */
    public static function fileName(string $hash): string
    {
        return "theme-{$hash}.css";
    }

    public static function hashFromFileName(string $file): ?string
    {
        return preg_match('/\Atheme-([0-9a-f]{16})\.css\z/', $file, $m) === 1 ? $m[1] : null;
    }
}
