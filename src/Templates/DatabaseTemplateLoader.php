<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Twig loader over the override rows for ONE theme (spec §3). The override map is
 * memoized for a single render — reset() clears it (called via
 * RenderTemplateLoader::resetForRender() before every render); freshness is
 * reload-per-render + version-keyed compile-cache keys, NEVER events.
 *
 * getSourceContext() re-runs the policy scan before the source reaches the compiler:
 * rows written around the admin API (SQL, migrations) are still enforced. The compile
 * cache is keyed to the version uuid AND TemplatePolicy::CACHE_VERSION, so the scan
 * runs once per saved version per policy revision.
 */
final class DatabaseTemplateLoader implements LoaderInterface
{
    /** @var array<string,string>|null path => current_version_uuid; null = reload */
    private ?array $map = null;

    public function __construct(
        private readonly TemplateRepository $repo,
        private readonly TemplateLinter $linter,
        private readonly string $theme,
    ) {
    }

    public function reset(): void
    {
        $this->map = null;
    }

    /** @return array<string,string> */
    private function map(): array
    {
        return $this->map ??= $this->repo->overrideMap($this->theme);
    }

    public function exists(string $name): bool
    {
        return isset($this->map()[$name]);
    }

    public function getCacheKey(string $name): string
    {
        $version = $this->map()[$name]
            ?? throw new LoaderError(sprintf('Template "%s" has no active DB override.', $name));
        // The policy version is part of the key (spec §3/§4): the compile-time lint
        // only runs on compile, so a policy TIGHTENING must orphan every previously
        // compiled DB template — otherwise old compilations keep executing unchecked.
        return 'db:' . $this->theme . ':' . $name . ':' . $version
            . ':policy:' . TemplatePolicy::CACHE_VERSION;
    }

    public function isFresh(string $name, int $time): bool
    {
        return true; // versions are immutable; a save (or policy bump) is a NEW cache key
    }

    public function getSourceContext(string $name): Source
    {
        $row = $this->repo->findCurrentSource($this->theme, $name);
        if ($row === null) {
            throw new LoaderError(sprintf('Template "%s" has no active DB override.', $name));
        }
        $violations = $this->linter->lint($row['source'], $name);
        if ($violations !== []) {
            throw new LoaderError(sprintf(
                'DB template "%s" (theme "%s") violates the template policy: %s',
                $name,
                $this->theme,
                implode('; ', array_map(
                    static fn (array $v): string => sprintf('line %d: %s', $v['line'], $v['message']),
                    $violations,
                )),
            ));
        }
        return new Source($row['source'], $name);
    }
}
