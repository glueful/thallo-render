<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;

/**
 * Slot rules (visual builder spec §5.4) for a block template whose type declares blocks fields
 * and renders its children through `blocks()`: every slot is named once by `slot_attrs('<slot>')`
 * on the element that holds it, and `slot_attrs()` never names a slot the type does not declare.
 * A type that renders its children's data inline (`renders_children_inline`) has no slot
 * elements and is exempt. A loop (type layouts plan B) renders its card through `loop_cards()`,
 * which puts the card's slot on the first card: that call names the slot its `field` argument says
 * (`card` when it is left out).
 */
final class SlotLint
{
    /**
     * @param list<string> $slots the type's blocks-field names
     * @return list<array{line:int,message:string}>
     */
    public static function lint(Node $module, array $slots): array
    {
        $violations = [];
        $named = [];
        self::walk($module, $slots, $named, $violations);
        foreach ($slots as $slot) {
            if (!isset($named[$slot])) {
                $violations[] = [
                    'line' => 1,
                    'message' => sprintf(
                        'Slot "%s" has no element: add slot_attrs(\'%s\') to the element that holds blocks(data.%s).',
                        $slot,
                        $slot,
                        $slot,
                    ),
                ];
            }
        }
        return $violations;
    }

    /**
     * @param list<string> $slots
     * @param array<string, true> $named
     * @param list<array{line:int,message:string}> $violations
     */
    private static function walk(Node $node, array $slots, array &$named, array &$violations): void
    {
        $name = $node instanceof FunctionExpression ? (string) $node->getAttribute('name') : '';
        if ($name === 'slot_attrs' || $name === 'loop_cards') {
            $line = max(1, $node->getTemplateLine());
            $args = [];
            foreach ($node->getNode('arguments') as $arg) {
                $args[] = $arg;
            }
            // slot_attrs('<slot>'); loop_cards(card, items, name, '<slot>' = 'card', class).
            $first = $name === 'slot_attrs'
                ? ($args[0] ?? null)
                : ($args[3] ?? new ConstantExpression('card', $line));
            if (!$first instanceof ConstantExpression || !is_string($first->getAttribute('value'))) {
                $violations[] = ['line' => $line, 'message' => "{$name}() slot must be a constant string."];
            } else {
                $slot = $first->getAttribute('value');
                if (!in_array($slot, $slots, true)) {
                    $violations[] = [
                        'line' => $line,
                        'message' => sprintf('Slot "%s" is not a blocks field of the block type.', $slot),
                    ];
                } else {
                    $named[$slot] = true;
                }
            }
        }
        foreach ($node as $child) {
            if ($child instanceof Node) {
                self::walk($child, $slots, $named, $violations);
            }
        }
    }
}
