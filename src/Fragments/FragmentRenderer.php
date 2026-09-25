<?php

declare(strict_types=1);

namespace Thallo\Render\Fragments;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\SiteContext;
use Thallo\Render\Templates\RenderTemplateLoader;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * Renders resolved roots in isolation through the same `blocks()` machinery and render-state
 * discipline the page render uses (visual builder spec §3.5): reset-before-render, canvas
 * annotation on, preview context on, the session's appearance override, the boot theme's
 * artifact bound. Each root comes back as its annotated wrapper — byte-identical to what the
 * whole-page render emits for that block, which is what the verification test proves.
 */
final class FragmentRenderer
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TwigFactory $twig,
        private readonly RenderContextExtension $extension,
        private readonly ThemeLocator $themes,
    ) {
    }

    /**
     * @param array<string,mixed> $content the shaped entry (as the page render's `entry`)
     * @param list<string> $roots
     * @return array<string,string> root id => annotated markup
     */
    public function render(
        array $content,
        string $locale,
        DocumentIndex $shaped,
        array $roots,
        ?string $accent = null,
        ?string $neutral = null,
    ): array {
        $env = $this->twig->environment();
        $loader = $env->getLoader();
        if ($loader instanceof RenderTemplateLoader) {
            $loader->resetForRender();
        }
        $this->extension->resetTags();
        $this->extension->resetPerRenderState();
        $this->extension->setAssetContext(null, null);
        $this->extension->bindTheme($this->themes);
        $this->extension->setThemeAppearanceOverride($accent, $neutral);
        $this->extension->setAnnotationScope('entry');
        $this->extension->setPreviewContext(true);
        $this->extension->setLocale($locale);
        $blockContext = [
            'entry' => $content,
            'site' => SiteContext::build($this->context, $locale),
            'current_path' => null,
            'region_slug' => null,
        ];
        $out = [];
        try {
            foreach ($roots as $id) {
                $block = $shaped->blockOf($id);
                if ($block === null) {
                    throw new \RuntimeException("fragment root {$id} is not in the document");
                }
                // The page reaches this root through depthOf() - 1 enclosing blocks() calls.
                $this->extension->setBlockDepth($shaped->depthOf($id) - 1);
                $out[$id] = $this->extension->blocks($env, $blockContext, [$block]);
            }
        } finally {
            // Leave the shared extension as a controller would find it.
            $this->extension->drainTags();
            $this->extension->resetPerRenderState();
            $this->extension->setAnnotationScope('none');
            $this->extension->setThemeAppearanceOverride(null, null);
        }
        return $out;
    }
}
