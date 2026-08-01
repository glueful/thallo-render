<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

use Thallo\Render\RenderContextExtension;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\TestExpression;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\ImportNode;
use Twig\Node\IncludeNode;
use Twig\Node\Node;
use Twig\Source;
use Twig\Template;

/**
 * THE enforcement engine for DB-authored templates (spec §4): parses the source in a
 * scratch environment (same extension set, so the render functions parse) and walks
 * the AST against TemplatePolicy. No runtime sandbox exists — what this scan allows
 * is what renders. Soundness rests on the render context being arrays/scalars only.
 *
 * Runs at SAVE (→ 422 with line numbers) and again in DatabaseTemplateLoader before
 * the source ever reaches the compiler (rows written around the API stay enforced).
 */
final class TemplateLinter
{
    public function __construct(private readonly RenderContextExtension $extension)
    {
    }

    /** @return list<array{line:int,message:string}> empty = clean */
    public function lint(string $source, string $name = 'template.twig'): array
    {
        $env = new Environment(new ArrayLoader([]), ['autoescape' => 'html']);
        $env->addExtension($this->extension);
        try {
            $module = $env->parse($env->tokenize(new Source($source, $name)));
        } catch (SyntaxError $e) {
            return [['line' => max(1, $e->getTemplateLine()), 'message' => $e->getRawMessage()]];
        }

        $violations = [];
        // extends target (module-level): constant string only (spec §4).
        if ($module->hasNode('parent') && !$module->getNode('parent') instanceof ConstantExpression) {
            $violations[] = [
                'line' => $module->getNode('parent')->getTemplateLine(),
                'message' => 'extends target must be a constant string.',
            ];
        }
        $this->walk($module, $violations);
        usort($violations, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        return $violations;
    }

    /** @param list<array{line:int,message:string}> $violations */
    private function walk(Node $node, array &$violations): void
    {
        $this->check($node, $violations);
        foreach ($node as $child) {
            if ($child instanceof Node) {
                $this->walk($child, $violations);
            }
        }
    }

    /** @param list<array{line:int,message:string}> $violations */
    private function check(Node $node, array &$violations): void
    {
        $line = max(1, $node->getTemplateLine());
        $deny = static function (string $message) use (&$violations, $line): void {
            $violations[] = ['line' => $line, 'message' => $message];
        };

        // Unknown node classes: default-deny (spec §4) — checked FIRST so a novel
        // construct is reported even when it carries no tag/name. Children are still
        // walked by walk() (nested violations get their own lines).
        if (!TemplatePolicy::isAllowedNodeClass($node::class)) {
            $deny(sprintf('Template construct "%s" is not allowed.', $node::class));
            return;
        }

        $tag = $node->getNodeTag();
        if ($tag !== null && $tag !== '' && !in_array($tag, TemplatePolicy::TAGS, true)) {
            $deny(sprintf('Tag "%s" is not allowed.', $tag));
        }

        if ($node instanceof FilterExpression) {
            $filterName = (string) $node->getAttribute('name');
            if (!in_array($filterName, TemplatePolicy::FILTERS, true)) {
                $deny(sprintf('Filter "%s" is not allowed.', $filterName));
            }
        }

        if ($node instanceof FunctionExpression) {
            $functionName = (string) $node->getAttribute('name');
            if (!in_array($functionName, TemplatePolicy::FUNCTIONS, true)) {
                $deny(sprintf('Function "%s" is not allowed.', $functionName));
            }
            // include() function target: constant string only (spec §4).
            if ($functionName === 'include') {
                $args = $node->getNode('arguments');
                $first = null;
                foreach ($args as $arg) {
                    $first = $arg;
                    break;
                }
                if (!$first instanceof ConstantExpression) {
                    $deny('include target must be a constant string.');
                }
            }
        }

        // Specialized nodes that replaced a function call (parent, block, attribute…)
        // stash the original name — police it like a normal function (Twig 3.27).
        if ($node->hasAttribute('sandboxed_function_name')) {
            $stashed = (string) $node->getAttribute('sandboxed_function_name');
            if (!in_array($stashed, TemplatePolicy::FUNCTIONS, true)) {
                $deny(sprintf('Function "%s" is not allowed.', $stashed));
            }
        }

        if ($node instanceof TestExpression) {
            $testName = (string) $node->getAttribute('name');
            if (!in_array($testName, TemplatePolicy::TESTS, true)) {
                $deny(sprintf('Test "%s" is not allowed.', $testName));
            }
        }

        if (
            $node instanceof GetAttrExpression
            && $node->getAttribute('type') === Template::METHOD_CALL
        ) {
            $deny('Method calls are not allowed.');
        }

        if ($node instanceof IncludeNode && !$node->getNode('expr') instanceof ConstantExpression) {
            $deny('include target must be a constant string.');
        }

        // Import target: ONLY the self-import shape (spec §4, gate-audit amendment —
        // task 7). Twig itself special-cases this exact shape at compile time
        // (ImportNode::compile(): ContextVariable named "_self" compiles to `$this`
        // instead of a `$this->load(...)` call) — matching that check means an import
        // of anything else always resolves through the loader, i.e. an arbitrary
        // template path. `{% import 'layout.twig' as x %}` denied; `{% import _self as
        // x %}` allowed — every shipped macro user already uses only the latter.
        if ($node instanceof ImportNode) {
            $expr = $node->getNode('expr');
            if (!($expr instanceof ContextVariable && $expr->getAttribute('name') === '_self')) {
                $deny('import target must be "_self" (e.g. {% import _self as x %}).');
            }
        }
    }
}
