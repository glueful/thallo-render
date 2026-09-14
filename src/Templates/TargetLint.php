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
 */
final class TargetLint
{
    private const HELPERS = ['style_classes', 'style_attrs'];

    /** @return list<array{line:int,message:string}> */
    public static function lint(Node $module, StyleTargets $targets): array
    {
        $violations = [];
        $used = [];
        self::walk($module, $targets, $used, $violations);
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
        return $violations;
    }

    /**
     * @param array<string, true> $used
     * @param list<array{line:int,message:string}> $violations
     */
    private static function walk(Node $node, StyleTargets $targets, array &$used, array &$violations): void
    {
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
                if (!in_array($name, $targets->names(), true)) {
                    $violations[] = [
                        'line' => $line,
                        'message' => sprintf('Style target "%s" is not declared by the block type.', $name),
                    ];
                } elseif ($helper === 'style_classes') {
                    $used[$name] = true;
                }
            }
        }
        foreach ($node as $child) {
            if ($child instanceof Node) {
                self::walk($child, $targets, $used, $violations);
            }
        }
    }
}
