<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Fonts\FontLibrarySnapshotView;

/**
 * Each workspace's fonts stylesheets (block typeface spec §3.4), written under storage/cache/fonts in
 * the workspace's own directory as `fonts-{hash}.css` and served by hash. Publish-then-link: a
 * snapshot's stylesheet is written before forSnapshot() returns, so no page links a file that does
 * not exist; an edit writes a new hash and the old file stays — the newest three and anything younger
 * than a day — so HTML already in browsers fetches the version it was rendered with. The publish and
 * prune follow CompiledStyleArtifacts', per workspace directory.
 */
final class FontsArtifacts
{
    public const RETAIN_NEWEST = 3;
    public const RETAIN_SECONDS = 86400;
    /** How long a published stylesheet goes untouched before a request refreshes it. */
    public const REFRESH_SECONDS = 3600;

    /** @var array<string, array{hash: string, css: string}> scope:hash => artifact */
    private array $memo = [];

    /** @param \Closure(): string $scope the current workspace's directory name */
    public function __construct(private readonly string $cacheDir, private readonly \Closure $scope)
    {
    }

    /** @return array{hash: string, css: string} the snapshot's stylesheet, published */
    public function forSnapshot(FontLibrarySnapshotView $snapshot): array
    {
        $css = FontsArtifact::compile($snapshot);
        $artifact = ['hash' => substr(hash('sha256', $css), 0, 16), 'css' => $css];
        $key = $this->dir() . ':' . $artifact['hash'];
        if (!isset($this->memo[$key])) {
            $this->publish($artifact['hash'], $css);
            $this->memo[$key] = $artifact;
        }
        return $artifact;
    }

    /** The current workspace's stylesheet with this hash, or null. */
    public function read(string $hash): ?string
    {
        $dir = $this->dir();
        if (isset($this->memo[$dir . ':' . $hash])) {
            return $this->memo[$dir . ':' . $hash]['css'];
        }
        $file = $dir . '/' . self::fileName($hash);
        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public static function fileName(string $hash): string
    {
        return "fonts-{$hash}.css";
    }

    public static function hashFromFileName(string $file): ?string
    {
        return preg_match('/\Afonts-([0-9a-f]{16})\.css\z/', $file, $m) === 1 ? $m[1] : null;
    }

    private function dir(): string
    {
        $scope = (string) preg_replace('/[^A-Za-z0-9_-]/', '-', ($this->scope)());
        return $this->cacheDir . '/' . ($scope === '' ? 'site' : $scope);
    }

    /** @throws \RuntimeException when the store is not writable */
    private function publish(string $hash, string $css): void
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create the fonts artifact store at {$dir}");
        }
        $file = $dir . '/' . self::fileName($hash);
        clearstatcache(true, $file);
        // Refreshed within the hour: nothing to do — retention keeps anything younger than a day, so
        // a request need not touch and prune again (final review).
        if (is_file($file) && (filemtime($file) ?: 0) > time() - self::REFRESH_SECONDS) {
            return;
        }
        if (!is_file($file)) {
            // Whole or not at all: a concurrent reader never sees a half-written file, which would
            // then be served as immutable.
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $css) === false || !@rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException("cannot write the fonts stylesheet {$file}");
            }
        }
        @touch($file);
        $this->prune($dir);
    }

    /** Keep the newest three stylesheets and anything younger than a day; drop the rest. */
    private function prune(string $dir): void
    {
        $files = glob($dir . '/fonts-*.css') ?: [];
        usort($files, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        foreach (array_slice($files, self::RETAIN_NEWEST) as $old) {
            if ((filemtime($old) ?: 0) < time() - self::RETAIN_SECONDS) {
                @unlink($old);
            }
        }
    }
}
