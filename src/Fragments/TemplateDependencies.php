<?php

declare(strict_types=1);

namespace Thallo\Render\Fragments;

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\IncludeNode;
use Twig\Node\Node;

/**
 * What a block template depends on beyond its own block (visual builder spec §3.5), read off
 * the template's AST through the render environment's loader — so a DB override is read like
 * the file it replaces. Constant includes are followed; a computed include (the shortcode
 * block) is unknowable and counts as every dependency at once. Templates that call
 * `claim_priority_image()` or read their list `index` depend on page order, `entries()` or the
 * request's `current_path` on the page, `block_script()` on runtime assets the page may not have
 * loaded yet. A priority-image claim is guarded by a resolved `media_image()` (the template
 * contract), so the `data.<field>` arguments of `media_image()` calls name the fields whose
 * value makes the claim reachable; a claim not guarded that way is reachable in every render.
 * Memoised per instance, one per render.
 */
final class TemplateDependencies
{
    private const ORDER = ['claim_priority_image'];
    private const PAGE = ['entries', 'facets'];
    private const ASSETS = ['block_script'];
    /** Context variables a fragment cannot reproduce: the block's list index, the page's path. */
    private const ORDER_VARIABLES = ['index'];
    private const PAGE_VARIABLES = ['current_path'];

    /**
     * @var array<string, array{
     *   order: bool, page: bool, assets: bool, templates: list<string>, claim_fields: ?list<string>
     * }>
     */
    private array $memo = [];

    /** @var array<string, ?string> */
    private array $hashes = [];

    public function __construct(private readonly Environment $env)
    {
    }

    public function pageOrderDependent(string $type): bool
    {
        return $this->scan($type)['order'];
    }

    public function pageDependent(string $type): bool
    {
        return $this->scan($type)['page'];
    }

    public function needsAssets(string $type): bool
    {
        return $this->scan($type)['assets'];
    }

    /** @return list<string> the block template and every template it includes (transitively) */
    public function templatesFor(string $type): array
    {
        return $this->scan($type)['templates'];
    }

    /**
     * The `data` fields whose value makes the template's priority-image claim reachable; null
     * when the claim cannot be tied to a field (it counts as reachable), [] when the template
     * never claims.
     *
     * @return list<string>|null
     */
    public function claimFields(string $type): ?array
    {
        return $this->scan($type)['claim_fields'];
    }

    /** The sha256 of a template's current source through the loader; null when it does not exist. */
    public function templateHash(string $name): ?string
    {
        if (!array_key_exists($name, $this->hashes)) {
            try {
                $this->hashes[$name] = hash('sha256', $this->env->getLoader()->getSourceContext($name)->getCode());
            } catch (LoaderError) {
                $this->hashes[$name] = null;
            }
        }
        return $this->hashes[$name];
    }

    /**
     * @return array{
     *   order: bool, page: bool, assets: bool, templates: list<string>, claim_fields: ?list<string>
     * }
     */
    private function scan(string $type): array
    {
        if (!isset($this->memo[$type])) {
            $calls = [];
            $templates = [];
            $unknown = false;
            $guards = ['fields' => [], 'unguarded' => false];
            $this->collect("blocks/{$type}.twig", $calls, $templates, $unknown, $guards);
            $claims = $unknown || self::any($calls, self::ORDER);
            $this->memo[$type] = [
                'order' => $claims || self::anyVariable($calls, self::ORDER_VARIABLES),
                'page' => $unknown
                    || self::any($calls, self::PAGE)
                    || self::anyVariable($calls, self::PAGE_VARIABLES),
                'assets' => $unknown || self::any($calls, self::ASSETS),
                'templates' => $templates,
                'claim_fields' => !$claims
                    ? []
                    : ($unknown || $guards['unguarded'] || $guards['fields'] === [] ? null : $guards['fields']),
            ];
        }
        return $this->memo[$type];
    }

    /**
     * @param array<string, true> $calls
     * @param list<string> $templates
     * @param array{fields: list<string>, unguarded: bool} $guards
     */
    private function collect(string $name, array &$calls, array &$templates, bool &$unknown, array &$guards): void
    {
        if (in_array($name, $templates, true)) {
            return;
        }
        try {
            $source = $this->env->getLoader()->getSourceContext($name);
            $module = $this->env->parse($this->env->tokenize($source));
        } catch (LoaderError | SyntaxError) {
            // No template (blocks() emits a placeholder) or one that cannot parse: nothing to
            // depend on, and nothing that could be verified either.
            return;
        }
        $templates[] = $name;
        $includes = [];
        self::walk($module, $calls, $includes, $unknown, $guards);
        foreach ($includes as $include) {
            $this->collect($include, $calls, $templates, $unknown, $guards);
        }
    }

    /**
     * @param array<string, true> $calls
     * @param list<string> $includes
     * @param array{fields: list<string>, unguarded: bool} $guards
     */
    private static function walk(Node $node, array &$calls, array &$includes, bool &$unknown, array &$guards): void
    {
        if ($node instanceof ContextVariable) {
            $calls['$' . (string) $node->getAttribute('name')] = true;
        }
        if ($node instanceof FunctionExpression) {
            $name = (string) $node->getAttribute('name');
            $calls[$name] = true;
            if ($name === 'include') {
                self::includeTarget(self::firstArgument($node), $includes, $unknown);
            }
            if ($name === 'media_image') {
                $field = self::dataField(self::firstArgument($node));
                if ($field === null) {
                    $guards['unguarded'] = true;
                } elseif (!in_array($field, $guards['fields'], true)) {
                    $guards['fields'][] = $field;
                }
            }
        }
        if ($node instanceof IncludeNode) {
            self::includeTarget($node->getNode('expr'), $includes, $unknown);
        }
        foreach ($node as $child) {
            if ($child instanceof Node) {
                self::walk($child, $calls, $includes, $unknown, $guards);
            }
        }
    }

    /** The `<field>` of a `data.<field>` expression; null for any other shape. */
    private static function dataField(?Node $expr): ?string
    {
        if (!$expr instanceof GetAttrExpression) {
            return null;
        }
        $node = $expr->getNode('node');
        $attribute = $expr->getNode('attribute');
        if (!$node instanceof ContextVariable || $node->getAttribute('name') !== 'data') {
            return null;
        }
        return $attribute instanceof ConstantExpression && is_string($attribute->getAttribute('value'))
            ? $attribute->getAttribute('value')
            : null;
    }

    /** @param list<string> $includes */
    private static function includeTarget(?Node $expr, array &$includes, bool &$unknown): void
    {
        if ($expr instanceof ConstantExpression && is_string($expr->getAttribute('value'))) {
            $includes[] = $expr->getAttribute('value');
            return;
        }
        $unknown = true;
    }

    private static function firstArgument(FunctionExpression $node): ?Node
    {
        foreach ($node->getNode('arguments') as $argument) {
            return $argument instanceof Node ? $argument : null;
        }
        return null;
    }

    /**
     * @param array<string, true> $calls
     * @param list<string> $names
     */
    private static function anyVariable(array $calls, array $names): bool
    {
        return self::any($calls, array_map(static fn (string $n): string => '$' . $n, $names));
    }

    /**
     * @param array<string, true> $calls
     * @param list<string> $names
     */
    private static function any(array $calls, array $names): bool
    {
        foreach ($names as $name) {
            if (isset($calls[$name])) {
                return true;
            }
        }
        return false;
    }
}
