<?php

declare(strict_types=1);

namespace Thallo\Render\Fragments;

use Thallo\Contracts\Style\BlockStyleRegistry;

/**
 * The minimal render roots for a set of affected blocks (visual builder spec §3.5): a block
 * whose parent renders its children's data inline (`renders_children_inline`) lifts to that
 * parent, repeatedly; ancestors absorb descendants so swaps never overlap; then every root's
 * subtree is inspected, before and after — a reachable priority-image claim (its outcome
 * depends on page order, and so do the outcomes after it), a block reading its list index, a
 * block type that was not on the page before (its runtime assets may not be loaded), or any
 * block on the page with a page dependency escalates to the whole page (`null`).
 */
final class RenderScopeResolver
{
    public function __construct(
        private readonly BlockStyleRegistry $registry,
        private readonly TemplateDependencies $dependencies,
    ) {
    }

    /**
     * @param list<string> $affected block ids present in `$after`
     * @return list<string>|null root ids in document order; null = whole page
     */
    public function resolve(array $affected, DocumentIndex $after, ?DocumentIndex $before): ?array
    {
        if ($affected === []) {
            return null;
        }
        foreach ($after->types() as $type) {
            if ($this->dependencies->pageDependent($type)) {
                return null;
            }
        }
        $roots = [];
        foreach ($affected as $id) {
            if (!$after->has($id)) {
                return null;
            }
            $roots[$this->lift($id, $after)] = true;
        }
        // Ancestors absorb descendants.
        $ids = array_keys($roots);
        $ids = array_values(array_filter($ids, static function (string $id) use ($ids, $after): bool {
            foreach ($after->ancestors($id) as $ancestor) {
                if (in_array($ancestor, $ids, true)) {
                    return false;
                }
            }
            return true;
        }));
        $beforeTypes = $before?->types() ?? [];
        foreach ($ids as $root) {
            foreach ($after->subtree($root) as $member) {
                $type = (string) $after->typeOf($member);
                if ($this->orderDependent($after, $member)) {
                    return null;
                }
                if (!in_array($type, $beforeTypes, true) && $this->dependencies->needsAssets($type)) {
                    return null;
                }
            }
            foreach ($before?->subtree($root) ?? [] as $member) {
                if ($this->orderDependent($before, $member)) {
                    return null; // the displayed render may have claimed what a later block now would
                }
            }
        }
        return array_values(array_filter($after->ids(), static fn (string $id): bool => in_array($id, $ids, true)));
    }

    /**
     * Whether rendering this block depends on page order: its template reads its list index, or
     * claims the priority image and the claim is reachable (a guarding field carries a value,
     * or the claim is not tied to a field).
     */
    private function orderDependent(DocumentIndex $doc, string $id): bool
    {
        $type = (string) $doc->typeOf($id);
        if (!$this->dependencies->pageOrderDependent($type)) {
            return false;
        }
        $fields = $this->dependencies->claimFields($type);
        if ($fields === null) {
            return true;
        }
        if ($fields === []) {
            return true; // order-dependent for another reason (the list index)
        }
        $data = $doc->blockOf($id)['data'] ?? [];
        foreach ($fields as $field) {
            $value = is_array($data) ? ($data[$field] ?? null) : null;
            if ($value !== null && $value !== '' && $value !== false && $value !== []) {
                return true;
            }
        }
        return false;
    }

    /** The block, or the nearest ancestor chain of parents that render their children inline. */
    private function lift(string $id, DocumentIndex $doc): string
    {
        $parent = $doc->parentOf($id);
        while ($parent !== null && $this->rendersChildrenInline((string) $doc->typeOf($parent))) {
            $id = $parent;
            $parent = $doc->parentOf($id);
        }
        return $id;
    }

    private function rendersChildrenInline(string $type): bool
    {
        return ($this->registry->flagsFor($type)['renders_children_inline'] ?? false) === true;
    }
}
