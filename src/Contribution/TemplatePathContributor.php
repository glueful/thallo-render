<?php

declare(strict_types=1);

namespace Thallo\Render\Contribution;

/**
 * Contributes a pack-owned template directory into the theme resolution chain (storefront-
 * rendering spec §5.2). Contributed dirs resolve BETWEEN the active app theme and the
 * thallo-render pack default: the app theme overrides a contribution, and a contribution
 * overrides the render default. Register with
 * {@see RenderContributionRegistry::registerTemplatePaths()} during provider boot(); consumed
 * once, at the registry's first frozen read, by {@see \Thallo\Render\ThemeLocator}.
 */
interface TemplatePathContributor
{
    /** Unique across every template-path contributor — duplicates are rejected at registration. */
    public function contributorId(): string;

    /** Ordering key when multiple contributors register: sorted by (priority, contributorId). */
    public function priority(): int;

    /** @return list<string> absolute template directories */
    public function templatePaths(): array;
}
