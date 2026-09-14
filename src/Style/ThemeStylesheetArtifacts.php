<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Render\Contribution\RenderContributionRegistry;
use Thallo\Render\ThemeLocator;

/**
 * Builds and stores the layered theme artifact per theme (visual builder spec §2.2, §2.4):
 * memoised per process, written under storage/cache/style so the served file is a cheap read,
 * and rebuilt whenever the content hash changes.
 */
final class ThemeStylesheetArtifacts
{
    /** @var array<string, ThemeStylesheetArtifact> theme name => artifact */
    private array $memo = [];

    public function __construct(
        private readonly string $cacheDir,
        private readonly ?RenderContributionRegistry $contributions = null,
    ) {
    }

    public function forTheme(ThemeLocator $theme): ThemeStylesheetArtifact
    {
        $name = $theme->activePaths()['name'];
        if (isset($this->memo[$name])) {
            return $this->memo[$name];
        }
        $dir = $theme->themeDir();
        $files = array_map(static fn (string $rel): string => $dir . '/' . $rel, $theme->vocabulary()->stylesheets());
        $artifact = ThemeStylesheetArtifact::build($files, $this->contributions?->frozenStylesheets() ?? []);
        $this->write($artifact);
        return $this->memo[$name] = $artifact;
    }

    /** The stored CSS for a hash, or null when no such artifact was built. */
    public function read(string $hash): ?string
    {
        $file = $this->cacheDir . '/' . ThemeStylesheetArtifact::fileName($hash);
        foreach ($this->memo as $artifact) {
            if ($artifact->hash === $hash) {
                return $artifact->css;
            }
        }
        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    private function write(ThemeStylesheetArtifact $artifact): void
    {
        $file = $this->cacheDir . '/' . ThemeStylesheetArtifact::fileName($artifact->hash);
        if (is_file($file)) {
            return;
        }
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        @file_put_contents($file, $artifact->css);
    }
}
