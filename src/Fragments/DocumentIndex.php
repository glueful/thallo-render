<?php

declare(strict_types=1);

namespace Thallo\Render\Fragments;

/**
 * Every block of a document by id (visual builder spec §3.5): its type, its parent and slot,
 * its position and document order. Root lists are the content type's blocks-typed fields;
 * regions are the block type's blocks-typed fields. A block without a string id is not indexed
 * (the preview never wraps it, so nothing could target it).
 */
final class DocumentIndex
{
    /**
     * @var array<string, array{
     *   type: string, parent: ?string, slot: string, index: int, depth: int, order: int,
     *   block: array<string,mixed>
     * }>
     */
    private array $blocks = [];

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $fields
     * @param list<string> $blockFields
     * @param callable(string): list<string> $regionsOf the region field names of a block type
     */
    public static function of(array $fields, array $blockFields, callable $regionsOf): self
    {
        $index = new self();
        $order = 0;
        foreach ($blockFields as $field) {
            $list = $fields[$field] ?? null;
            if (is_array($list) && array_is_list($list)) {
                $index->walk($list, null, $field, 1, $order, $regionsOf);
            }
        }
        return $index;
    }

    /**
     * @param list<mixed> $list
     * @param callable(string): list<string> $regionsOf
     */
    private function walk(
        array $list,
        ?string $parent,
        string $slot,
        int $depth,
        int &$order,
        callable $regionsOf,
    ): void {
        foreach ($list as $i => $block) {
            if (!is_array($block) || !is_string($block['id'] ?? null) || !is_string($block['type'] ?? null)) {
                continue;
            }
            $this->blocks[$block['id']] = [
                'type' => $block['type'],
                'parent' => $parent,
                'slot' => $slot,
                'index' => (int) $i,
                'depth' => $depth,
                'order' => $order++,
                'block' => $block,
            ];
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            foreach ($regionsOf($block['type']) as $region) {
                $children = $data[$region] ?? null;
                if (is_array($children) && array_is_list($children)) {
                    $this->walk($children, $block['id'], $region, $depth + 1, $order, $regionsOf);
                }
            }
        }
    }

    public function has(string $id): bool
    {
        return isset($this->blocks[$id]);
    }

    public function typeOf(string $id): ?string
    {
        return $this->blocks[$id]['type'] ?? null;
    }

    /** The parent block's id; null for a root-level or unknown block. */
    public function parentOf(string $id): ?string
    {
        return $this->blocks[$id]['parent'] ?? null;
    }

    public function slotOf(string $id): ?string
    {
        return $this->blocks[$id]['slot'] ?? null;
    }

    /** How many blocks() calls enclose the block on the page: 1 at the root; 0 for an unknown block. */
    public function depthOf(string $id): int
    {
        return $this->blocks[$id]['depth'] ?? 0;
    }

    /** @return array<string,mixed>|null */
    public function blockOf(string $id): ?array
    {
        return $this->blocks[$id]['block'] ?? null;
    }

    /** @return list<string> nearest first */
    public function ancestors(string $id): array
    {
        $out = [];
        $parent = $this->parentOf($id);
        while ($parent !== null) {
            $out[] = $parent;
            $parent = $this->parentOf($parent);
        }
        return $out;
    }

    /** @return list<string> the block and every descendant, in document order */
    public function subtree(string $id): array
    {
        if (!$this->has($id)) {
            return [];
        }
        $out = [];
        foreach ($this->ids() as $candidate) {
            if ($candidate === $id || in_array($id, $this->ancestors($candidate), true)) {
                $out[] = $candidate;
            }
        }
        return $out;
    }

    /** @return list<string> every block id in document order */
    public function ids(): array
    {
        $ids = array_keys($this->blocks);
        usort($ids, fn (string $a, string $b): int => $this->blocks[$a]['order'] <=> $this->blocks[$b]['order']);
        return $ids;
    }

    /** @return list<string> the distinct block types on the page */
    public function types(): array
    {
        return array_values(array_unique(array_column($this->blocks, 'type')));
    }
}
