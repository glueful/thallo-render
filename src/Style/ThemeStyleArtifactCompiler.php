<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Style\StyleArtifactCompiler;
use Thallo\Contracts\Style\StyleCompileFailed;
use Thallo\Render\ThemeLocator;

/**
 * The render pack's {@see StyleArtifactCompiler}: resolves the theme through the same ladder
 * the render uses, compiles its vocabulary and publishes the artifact. Any failure (unknown
 * theme, broken vocabulary, unwritable store) is one {@see StyleCompileFailed}.
 */
final class ThemeStyleArtifactCompiler implements StyleArtifactCompiler
{
    /** @param list<string> $contributedTemplatePaths */
    public function __construct(
        private readonly CompiledStyleArtifacts $artifacts,
        private readonly ThemeLocator $active,
        private readonly string $themesDir,
        private readonly array $contributedTemplatePaths = [],
    ) {
    }

    public function compile(?string $theme = null): string
    {
        try {
            $locator = $theme === null
                ? $this->active
                : new ThemeLocator($theme, $this->themesDir, null, $this->contributedTemplatePaths);
            if ($theme !== null && $locator->activePaths()['name'] !== $theme) {
                throw new \RuntimeException("theme \"{$theme}\" not found");
            }
            return $this->artifacts->forTheme($locator)['hash'];
        } catch (StyleCompileFailed $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StyleCompileFailed(
                'the compiled style artifact for theme "' . ($theme ?? 'active') . '" did not compile: '
                    . $e->getMessage(),
                0,
                $e,
            );
        }
    }
}
