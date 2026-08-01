<?php

declare(strict_types=1);

namespace Thallo\Render\Contribution;

/**
 * One registry, two contribution kinds (storefront-rendering spec §5.1/§5.2). Packs register
 * {@see ReservedPathContributor}/{@see TemplatePathContributor} instances throughout provider
 * boot(); the FIRST `frozen*()` read — naturally the first time a request resolves
 * {@see \Thallo\Render\ReservedPaths}, {@see \Thallo\Render\ThemeLocator}, or
 * {@see \Thallo\Render\TwigFactory} from the container, which is always after every provider's
 * register()+boot() has run — takes ONE deterministic snapshot of BOTH kinds together, ordered
 * `(priority, contributorId)`. A registration attempt after that point is a boot-ordering bug,
 * not a runtime condition: it fails loudly rather than silently dropping the late contributor.
 * Duplicate contributor ids and duplicate reserved paths / template dirs are likewise rejected —
 * never first-wins, so a colliding contribution can never silently shadow another pack's.
 */
final class RenderContributionRegistry
{
    /** @var array<string, ReservedPathContributor> */
    private array $reserved = [];

    /** @var array<string, TemplatePathContributor> */
    private array $templates = [];

    private bool $frozen = false;

    /** @var array{prefixes: list<string>, exacts: list<string>}|null */
    private ?array $frozenReservedSnapshot = null;

    /** @var list<array{contributor_id: string, dir: string}>|null */
    private ?array $frozenTemplateSnapshot = null;

    public function registerReservedPaths(ReservedPathContributor $contributor): void
    {
        $id = $contributor->contributorId();
        $this->guardNotFrozen($id);
        if (isset($this->reserved[$id])) {
            throw new \LogicException("Duplicate reserved-path contributor id '{$id}'.");
        }
        $this->reserved[$id] = $contributor;
    }

    public function registerTemplatePaths(TemplatePathContributor $contributor): void
    {
        $id = $contributor->contributorId();
        $this->guardNotFrozen($id);
        if (isset($this->templates[$id])) {
            throw new \LogicException("Duplicate template-path contributor id '{$id}'.");
        }
        $this->templates[$id] = $contributor;
    }

    /** @return array{prefixes: list<string>, exacts: list<string>} */
    public function frozenReserved(): array
    {
        $this->freeze();
        /** @var array{prefixes: list<string>, exacts: list<string>} $snapshot */
        $snapshot = $this->frozenReservedSnapshot;
        return $snapshot;
    }

    /** @return list<string> */
    public function frozenTemplatePaths(): array
    {
        return array_column($this->frozenTemplateContributions(), 'dir');
    }

    /**
     * The same frozen snapshot as {@see frozenTemplatePaths()}, with contributor ids
     * (admin-contributed-templates spec §1) — ids are for deterministic resolution and
     * diagnostics only, never for public API responses.
     *
     * @return list<array{contributor_id: string, dir: string}>
     */
    public function frozenTemplateContributions(): array
    {
        $this->freeze();
        /** @var list<array{contributor_id: string, dir: string}> $snapshot */
        $snapshot = $this->frozenTemplateSnapshot;
        return $snapshot;
    }

    private function guardNotFrozen(string $contributorId): void
    {
        if ($this->frozen) {
            throw new \RuntimeException(
                "RenderContributionRegistry is frozen: contributor '{$contributorId}' registered "
                . 'after the frozen snapshot was already read. Every reserved-path/template-path '
                . 'contributor must register during provider boot(), before the first request '
                . 'resolves ReservedPaths, ThemeLocator, or TwigFactory from the container.',
            );
        }
    }

    /** Builds BOTH snapshots atomically; only flips `frozen` once both succeed. */
    private function freeze(): void
    {
        if ($this->frozen) {
            return;
        }

        $reservedSnapshot = $this->buildReservedSnapshot();
        $templateSnapshot = $this->buildTemplateSnapshot();

        $this->frozenReservedSnapshot = $reservedSnapshot;
        $this->frozenTemplateSnapshot = $templateSnapshot;
        $this->frozen = true;
    }

    /** @return array{prefixes: list<string>, exacts: list<string>} */
    private function buildReservedSnapshot(): array
    {
        $prefixes = [];
        $exacts = [];
        $seenPrefixes = [];
        $seenExacts = [];
        foreach ($this->ordered($this->reserved) as $contributor) {
            foreach ($contributor->reservedPrefixes() as $prefix) {
                if (isset($seenPrefixes[$prefix])) {
                    throw new \LogicException("Duplicate reserved path prefix '{$prefix}'.");
                }
                $seenPrefixes[$prefix] = true;
                $prefixes[] = $prefix;
            }
            foreach ($contributor->reservedExacts() as $exact) {
                if (isset($seenExacts[$exact])) {
                    throw new \LogicException("Duplicate reserved exact path '{$exact}'.");
                }
                $seenExacts[$exact] = true;
                $exacts[] = $exact;
            }
        }
        return ['prefixes' => $prefixes, 'exacts' => $exacts];
    }

    /** @return list<array{contributor_id: string, dir: string}> */
    private function buildTemplateSnapshot(): array
    {
        $rows = [];
        $seen = [];
        foreach ($this->ordered($this->templates) as $contributor) {
            foreach ($contributor->templatePaths() as $dir) {
                if (isset($seen[$dir])) {
                    throw new \LogicException("Duplicate contributed template path '{$dir}'.");
                }
                $seen[$dir] = true;
                $rows[] = ['contributor_id' => $contributor->contributorId(), 'dir' => $dir];
            }
        }
        return $rows;
    }

    /**
     * @param array<string, ReservedPathContributor>|array<string, TemplatePathContributor> $contributors
     * @return list<ReservedPathContributor>|list<TemplatePathContributor>
     */
    private function ordered(array $contributors): array
    {
        $rows = array_values($contributors);
        usort($rows, static function ($a, $b): int {
            $priorityComparison = $a->priority() <=> $b->priority();
            return $priorityComparison !== 0
                ? $priorityComparison
                : $a->contributorId() <=> $b->contributorId();
        });
        return $rows;
    }
}
