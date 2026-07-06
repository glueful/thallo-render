<?php

declare(strict_types=1);

namespace Thallo\Render;

use Thallo\Render\Templates\DatabaseTemplateLoader;
use Thallo\Render\Templates\RenderTemplateLoader;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Builds the render Twig environment: active-theme-first loader (per-template fallback
 * to the pack default), autoescape html, filesystem compile cache with auto_reload
 * (recompiles on template mtime change — zero-friction theme development).
 */
final class TwigFactory
{
    public function __construct(
        private readonly ThemeLocator $themes,
        private readonly RenderContextExtension $extension,
        private readonly string $cacheDir,
        // DB-edited templates (spec §3): when present, overrides load DB-first through
        // the pack composite. Null = pure filesystem, byte-identical to pre-feature.
        private readonly ?DatabaseTemplateLoader $dbTemplates = null,
    ) {
    }

    public function environment(): Environment
    {
        $paths = $this->themes->activePaths();
        $fs = new FilesystemLoader($paths['templates']);
        $twig = new Environment(
            $this->dbTemplates === null ? $fs : new RenderTemplateLoader($this->dbTemplates, $fs),
            [
                'autoescape' => 'html',
                'cache' => rtrim($this->cacheDir, '/') . '/' . $paths['name'],
                'auto_reload' => true,
                'strict_variables' => false,
            ],
        );
        $twig->addExtension($this->extension);
        return $twig;
    }
}
