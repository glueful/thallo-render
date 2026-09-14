<?php

declare(strict_types=1);

namespace Thallo\Render\Fragments;

/**
 * The blocks an apply's operations touched (visual builder spec §3.5), validated against the
 * accepted-before and validated-after documents so a stale or inconsistent operations list
 * can never target the wrong element: a value or class change yields the block, an insert,
 * removal or duplicate yields the parent, a move yields both parents. `null` is the
 * whole-page path: no operations, no accepted-before document, a root-level structural
 * change, a page-settings change, an unknown operation, or an operation the two documents
 * do not agree with.
 */
final class AffectedBlocks
{
    private const OWN = [
        'SetField', 'SetSetting', 'SetAdvanced',
        'ApplyStyleClass', 'RemoveStyleClass', 'ReorderStyleClasses', 'DetachStyleClass',
    ];

    /**
     * @param list<array<string,mixed>> $operations
     * @return list<string>|null affected block ids in document order; null = whole page
     */
    public static function derive(array $operations, ?DocumentIndex $before, DocumentIndex $after): ?array
    {
        if ($operations === [] || $before === null) {
            return null;
        }
        $affected = [];
        foreach ($operations as $op) {
            $ids = self::affectedBy($op, $before, $after);
            if ($ids === null) {
                return null;
            }
            foreach ($ids as $id) {
                $affected[$id] = true;
            }
        }
        return array_values(array_filter($after->ids(), static fn (string $id): bool => isset($affected[$id])));
    }

    /**
     * @param array<string,mixed> $op
     * @return list<string>|null
     */
    private static function affectedBy(array $op, DocumentIndex $before, DocumentIndex $after): ?array
    {
        $type = $op['type'] ?? null;
        if (in_array($type, self::OWN, true)) {
            $block = self::id($op['block'] ?? null);
            return $block !== null && $before->has($block) && $after->has($block) ? [$block] : null;
        }
        switch ($type) {
            case 'InsertBlock':
            case 'DuplicateBlock':
                $parent = self::parentOf($op['position'] ?? null, $after);
                $inserted = self::id($op['block']['id'] ?? null);
                return $parent !== null && $inserted !== null && $after->parentOf($inserted) === $parent
                    ? [$parent]
                    : null;
            case 'InsertBlocks':
                $parent = self::parentOf($op['position'] ?? null, $after);
                if ($parent === null) {
                    return null;
                }
                foreach ((array) ($op['blocks'] ?? []) as $block) {
                    $inserted = self::id(is_array($block) ? ($block['id'] ?? null) : null);
                    if ($inserted === null || $after->parentOf($inserted) !== $parent) {
                        return null;
                    }
                }
                return [$parent];
            case 'RemoveBlock':
                $parent = self::parentOf($op['position'] ?? null, $after);
                $removed = self::id($op['block']['id'] ?? null);
                return $parent !== null && $removed !== null && $before->has($removed) && !$after->has($removed)
                    ? [$parent]
                    : null;
            case 'MoveBlock':
                $block = self::id($op['block'] ?? null);
                $from = self::parentOf($op['from'] ?? null, $before);
                $to = self::parentOf($op['to'] ?? null, $after);
                if ($block === null || $from === null || $to === null || !$after->has($from)) {
                    return null;
                }
                if ($before->parentOf($block) !== $from || $after->parentOf($block) !== $to) {
                    return null;
                }
                return $from === $to ? [$to] : [$from, $to];
            default:
                // SetPageSettings (an entry field outside block wrappers) and anything unknown.
                return null;
        }
    }

    /** A position's parent, present in the document; null for a root list or an unknown parent. */
    private static function parentOf(mixed $position, DocumentIndex $doc): ?string
    {
        $parent = self::id(is_array($position) ? ($position['parent'] ?? null) : null);
        return $parent !== null && $doc->has($parent) ? $parent : null;
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
