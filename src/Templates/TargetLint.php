<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

use Thallo\Contracts\Style\StyleTargets;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;

/**
 * The style-target rules of the template lint (visual builder spec §2.5), applied to a block
 * template whose type declares targets: every declared target is styled somewhere in the
 * template, no undeclared target is styled, and a target name is always a constant string (a
 * computed name could never be checked here, so it is not allowed).
 *
 * Plus the layout-item rule (container-layout spec §3.6): the target that carries `layout.item`
 * must be the block's OUTERMOST element, because that element is what participates in a parent
 * container's flex or grid layout. The linter sees Twig nodes, not HTML, so the rule is expressed
 * the way it holds in every template: the item target is the FIRST target styled in the template.
 */
final class TargetLint
{
    private const HELPERS = ['style_classes', 'style_attrs'];

    /** @return list<array{line:int,message:string}> */
    public static function lint(Node $module, StyleTargets $targets): array
    {
        $violations = [];
        $used = [];
        $order = [];
        self::walk($module, $targets, $used, $violations, $order);

        // The item target is styled first: a later one would be an inner element, which does not
        // participate in the parent's layout.
        $item = $targets->targetFor('layout.span');
        if ($item !== null && $order !== [] && $order[0] !== $item) {
            $violations[] = [
                'line' => 1,
                'message' => sprintf(
                    'layout.item target "%s" must be the template\'s outermost element: '
                        . '"%s" is styled first.',
                    $item,
                    $order[0],
                ),
            ];
        }
        foreach ($targets->names() as $name) {
            if (!isset($used[$name])) {
                $violations[] = [
                    'line' => 1,
                    'message' => sprintf(
                        'Declared style target "%s" is never styled: add style_classes(\'%s\') to its element.',
                        $name,
                        $name,
                    ),
                ];
            }
        }
        // A part (a links block's links) is styled the same way, on each element it names.
        foreach ($targets->parts() as $name) {
            if (!isset($used[$name])) {
                $violations[] = [
                    'line' => 1,
                    'message' => sprintf(
                        'Declared style part "%s" is never styled: add style_classes(\'%s\') to its elements.',
                        $name,
                        $name,
                    ),
                ];
            }
        }
        return $violations;
    }

    /**
     * @param array<string, true> $used
     * @param list<array{line:int,message:string}> $violations
     * @param list<string> $order the targets styled, in template order
     */
    private static function walk(
        Node $node,
        StyleTargets $targets,
        array &$used,
        array &$violations,
        array &$order,
    ): void {
        $helper = $node instanceof FunctionExpression ? (string) $node->getAttribute('name') : '';
        if (in_array($helper, self::HELPERS, true)) {
            $line = max(1, $node->getTemplateLine());
            $first = null;
            foreach ($node->getNode('arguments') as $arg) {
                $first = $arg;
                break;
            }
            if (!$first instanceof ConstantExpression || !is_string($first->getAttribute('value'))) {
                $violations[] = ['line' => $line, 'message' => "{$helper}() target must be a constant string."];
            } else {
                $name = $first->getAttribute('value');
                if (!in_array($name, $targets->names(), true) && !$targets->isPart($name)) {
                    $violations[] = [
                        'line' => $line,
                        'message' => sprintf('Style target "%s" is not declared by the block type.', $name),
                    ];
                } elseif ($helper === 'style_classes') {
                    $used[$name] = true;
                    // The outermost-element rule orders targets; a part is inside the block.
                    if (!$targets->isPart($name) && !in_array($name, $order, true)) {
                        $order[] = $name;
                    }
                }
            }
        }
        foreach ($node as $child) {
            if ($child instanceof Node) {
                self::walk($child, $targets, $used, $violations, $order);
            }
        }
    }
}
