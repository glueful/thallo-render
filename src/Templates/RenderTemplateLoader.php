<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * DB-first, filesystem-second composite (spec §3). Deliberately NOT Twig's ChainLoader:
 * ChainLoader memoizes exists() in a persistent $hasSourceCache with no invalidation
 * path — a miss before the first save would pin a DB-only template to "not found" for
 * the process lifetime. This composite keeps NO exists-cache of its own;
 * resetForRender() clears the one piece of state that can go stale (the DB map).
 */
final class RenderTemplateLoader implements LoaderInterface
{
    public function __construct(
        private readonly DatabaseTemplateLoader $db,
        private readonly FilesystemLoader $fs,
    ) {
    }

    /** Called by the render controller before EVERY render (resetTags() family). */
    public function resetForRender(): void
    {
        $this->db->reset();
    }

    public function exists(string $name): bool
    {
        return $this->db->exists($name) || $this->fs->exists($name);
    }

    public function getSourceContext(string $name): Source
    {
        return $this->db->exists($name)
            ? $this->db->getSourceContext($name)
            : $this->fs->getSourceContext($name);
    }

    public function getCacheKey(string $name): string
    {
        return $this->db->exists($name) ? $this->db->getCacheKey($name) : $this->fs->getCacheKey($name);
    }

    public function isFresh(string $name, int $time): bool
    {
        return $this->db->exists($name) ? $this->db->isFresh($name, $time) : $this->fs->isFresh($name, $time);
    }
}
