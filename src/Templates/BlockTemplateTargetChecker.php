<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

use Thallo\Contracts\Style\BlockTemplateTargetCheck;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Render\ThemeLocator;

/**
 * The renderer's answer to {@see BlockTemplateTargetCheck}: finds the template the active theme
 * renders the block with — its DB override first, else the theme's own file — and runs the
 * style-target lint over it with the PROSPECTIVE targets.
 */
final class BlockTemplateTargetChecker implements BlockTemplateTargetCheck
{
    public function __construct(
        private readonly TemplateLinter $linter,
        private readonly ThemeLocator $theme,
        private readonly ?TemplateRepository $templates = null,
    ) {
    }

    public function problems(string $blockSlug, StyleTargets $targets): array
    {
        $name = 'blocks/' . $blockSlug . '.twig';
        $source = $this->sourceOf($name);
        if ($source === null) {
            return [];
        }
        return array_map(
            static fn (array $violation): string => $violation['message'],
            $this->linter->lintTargets($source, $name, $targets),
        );
    }

    private function sourceOf(string $name): ?string
    {
        $paths = $this->theme->activePaths();
        $row = $this->templates?->findCurrentSource($paths['name'], $name);
        if ($row !== null) {
            return (string) $row['source'];
        }
        foreach ($paths['templates'] as $dir) {
            $file = $dir . '/' . $name;
            if (is_file($file)) {
                return (string) file_get_contents($file);
            }
        }
        return null;
    }
}
