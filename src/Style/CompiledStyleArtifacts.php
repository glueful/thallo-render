<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Render\ThemeLocator;

/**
 * The compiled style artifact per theme (visual builder spec §2.4): compiled from the theme's
 * vocabulary, written under storage/cache/style as `settings-{hash}.css`, served by hash,
 * memoised per process. Publish-then-serve: a new hash is written before any page links it,
 * and previous artifacts are retained (the newest three, and anything younger than a day) so
 * HTML already in browsers can still fetch its stylesheet.
 */
final class CompiledStyleArtifacts
{
    public const RETAIN_NEWEST = 3;
    public const RETAIN_SECONDS = 86400;

    /** @var array<string, array{hash: string, css: string}> theme name => artifact */
    private array $memo = [];

    public function __construct(private readonly string $cacheDir)
    {
    }

    /** @return array{hash: string, css: string} */
    public function forTheme(ThemeLocator $theme): array
    {
        $name = $theme->activePaths()['name'];
        if (isset($this->memo[$name])) {
            return $this->memo[$name];
        }
        $vocabulary = $theme->vocabulary();
        $artifact = ['hash' => StyleCompiler::hash($vocabulary), 'css' => StyleCompiler::compile($vocabulary)];
        $this->publish($artifact['hash'], $artifact['css']);
        return $this->memo[$name] = $artifact;
    }

    public function read(string $hash): ?string
    {
        foreach ($this->memo as $artifact) {
            if ($artifact['hash'] === $hash) {
                return $artifact['css'];
            }
        }
        $file = $this->cacheDir . '/' . self::fileName($hash);
        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public static function fileName(string $hash): string
    {
        return "settings-{$hash}.css";
    }

    public static function hashFromFileName(string $file): ?string
    {
        return preg_match('/\Asettings-([0-9a-f]{16})\.css\z/', $file, $m) === 1 ? $m[1] : null;
    }

    /** @throws \RuntimeException when the store is not writable (nothing is memoised then) */
    private function publish(string $hash, string $css): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException("cannot create the style artifact store at {$this->cacheDir}");
        }
        $file = $this->cacheDir . '/' . self::fileName($hash);
        if (!is_file($file) && @file_put_contents($file, $css) === false) {
            throw new \RuntimeException("cannot write the compiled style artifact {$file}");
        }
        @touch($file);
        $this->prune();
    }

    /** Keep the newest three artifacts and anything younger than a day; drop the rest. */
    private function prune(): void
    {
        $files = glob($this->cacheDir . '/settings-*.css') ?: [];
        usort($files, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        foreach (array_slice($files, self::RETAIN_NEWEST) as $old) {
            if ((filemtime($old) ?: 0) < time() - self::RETAIN_SECONDS) {
                @unlink($old);
            }
        }
    }
}
