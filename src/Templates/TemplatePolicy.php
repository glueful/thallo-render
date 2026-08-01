<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

/**
 * The v1 allowlists for DB-authored templates (spec §4) — DATA ONLY. TemplateLinter's
 * AST walk is the enforcement engine; nothing here (and nothing in Twig's sandbox)
 * runs at render time. NOT app-configurable in v1.
 *
 * Node classes: default-deny — EXACT classes only, each reviewed one-by-one (pure
 * data/control flow, no code execution, no I/O, no object reach). There is no
 * namespace-level allow: a new/unreviewed Twig node class is denied even when it
 * lives in a familiar-looking namespace (SpreadUnary, MatchesBinary — preg_match on
 * template-supplied patterns — and HasEvery/HasSomeBinary — arrow-function carriers —
 * are all deliberately absent). Class-string entries that don't exist in the
 * installed Twig are harmless (they never match); completeness is driven by the
 * representative-template test.
 */
final class TemplatePolicy
{
    /**
     * Compiled-cache invalidator: part of every DB template's compile-cache key
     * (db:{theme}:{path}:{version_uuid}:policy:{CACHE_VERSION}). The compile-time lint
     * runs only when a template compiles — without this, a template compiled under an
     * older, looser policy would keep executing after a tightening. BUMP THIS ON EVERY
     * allowlist or enforcement change (tags/filters/functions/tests/node classes/
     * linter rules); the next render then recompiles — and re-lints — everything.
     */
    // bumped: form_render joined the function allowlist (form-block spec §4)
    // bumped: runtime_script joined the function allowlist (theme-runtime spec §2.3)
    // bumped: seo_head joined the function allowlist (seo-head spec §3)
    // bumped: font_faces_style joined the function allowlist (default-theme-font spec §3)
    // bumped: shop_wishlist_scope + shop_wishlist_url joined the allowlist (storefront-v1 spec §5)
    // bumped: shop_styles_url joined the function allowlist (head stylesheet link)
    // bumped: admin-contributed-templates spec §3 — shop URL trio + json_script + the eight
    //         render functions the shipped default theme uses (individually reviewed;
    //         media_image gated behind normalizeWidths()), while range()/RangeBinary are denied
    //         to prevent pre-call unbounded allocation. Every shipped template round-trips through the
    //         editor (exception-free lint gate).
    // NOT bumped — vocabulary alignment (admin-contributed-templates task 7, gate-audit
    //         amendment): the exception-free lint gate's dry run (33 failures) found sanctioned
    //         template vocabulary the v17 policy never listed — editable_text/style_hook (already
    //         wired as filters, just missing from FILTERS), and macro/import (constrained below to
    //         the self-import shape by TemplateLinter; every shipped macro user already conforms).
    //         hex_color/numeric_clamp are NEW bounded PHP helpers that replace the two |matches
    //         regex checks blocks/style.twig used directly — MatchesBinary/`matches` stays DENIED
    //         (ReDoS posture); the patterns now live in PHP, bound to one call site each, never in
    //         template source. This widens v17's own vocabulary to match what already shipped; it
    //         does not loosen anything previously enforced, so compiled caches keyed on v17 are
    //         still valid — no recompile is required.
    public const CACHE_VERSION = 17;

    public const TAGS = ['if', 'for', 'set', 'block', 'extends', 'include', 'verbatim', 'macro', 'import'];

    public const FILTERS = [
        'abs', 'batch', 'capitalize', 'column', 'date', 'date_modify', 'default',
        'editable_text', 'escape', 'e', 'first', 'format', 'hex_color', 'join', 'json_encode',
        'keys', 'last', 'length', 'lower', 'merge', 'nl2br', 'number_format', 'numeric_clamp',
        'replace', 'reverse', 'round', 'safe_html', 'safe_url', 'slice', 'sort', 'split',
        'striptags', 'style_hook', 'title', 'trim', 'upper', 'url_encode',
    ];

    public const FUNCTIONS = [
        'menu', 'path', 'asset', 'facets', 'blocks', 'media', 'site_logo', 'video_embed', 'icon',
        'region_blocks', 'region_settings', 'site_favicon', 'custom_css', 'form_render',
        'runtime_script', 'seo_head', 'font_faces_style',
        'shop_wishlist_scope', 'shop_wishlist_url', 'shop_styles_url',
        'shop_product_url', 'shop_category_url', 'shop_index_url', 'json_script',
        'entries', 'is_preview', 'media_image', 'claim_priority_image',
        'color_mode_enabled', 'color_mode_script', 'theme_colors_style', 'theme_style_scope',
        'include', 'parent', 'block', 'cycle', 'date', 'min', 'max',
    ];

    public const TESTS = [
        'defined', 'empty', 'even', 'iterable', 'null', 'odd', 'true',
        'same as', 'divisible by', 'sequence', 'mapping',
    ];

    /**
     * EXACT node classes known safe — enumerated from the installed Twig 3.27
     * (vendor/twig/twig/src/Node), reviewed individually. NO namespace-level allow.
     *
     * Deliberately absent (each is a decision, not an oversight):
     *   - Expression\Unary\SpreadUnary            — `...` spread; not needed in v1
     *   - Expression\Binary\MatchesBinary         — preg_match on template patterns (ReDoS)
     *   - Expression\Binary\HasEveryBinary/HasSomeBinary — arrow-function carriers
     *   - Expression\Binary\SetBinary + *DestructuringSetBinary — set internals beyond plain assignment
     *   - Expression\ArrowFunctionExpression      — how map/filter/reduce stay out
     *   - MethodCallExpression, InlinePrint, VariadicExpression, ListExpression,
     *     Test\ConstantTest, Filter\RawFilter
     *
     * Gate-audit amendment (admin-contributed-templates task 7): ForElseNode (`{% for %}
     * … {% else %}`), ImportNode, MacroNode and Expression\MacroReferenceExpression joined
     * the allowlist — all pure control-flow/declaration, no code execution, no I/O, no
     * object reach. ImportNode is further constrained by TemplateLinter to the self-import
     * shape (`{% import _self as x %}`) only; every shipped macro user (navigation.twig,
     * blog_posts.twig, pricing_table.twig, container.twig) already conforms.
     *
     * @var list<class-string|string>
     */
    public const NODE_CLASSES = [
        \Twig\Node\ModuleNode::class,
        \Twig\Node\BodyNode::class,
        \Twig\Node\Node::class,
        // Twig 3.28: a childless, attributeless structural marker ("has global
        // side effects but does not generate template code") — reviewed. The
        // constructs it carries are policed individually by their own nodes/tags
        // (most remain denied; macro/import are now sanctioned — see above).
        \Twig\Node\ConfigNode::class,
        \Twig\Node\Nodes::class,
        \Twig\Node\TextNode::class,
        \Twig\Node\PrintNode::class,
        \Twig\Node\SetNode::class,
        \Twig\Node\IfNode::class,
        \Twig\Node\ForNode::class,
        \Twig\Node\ForLoopNode::class,
        \Twig\Node\ForElseNode::class,
        \Twig\Node\BlockNode::class,
        \Twig\Node\BlockReferenceNode::class,
        \Twig\Node\IncludeNode::class,
        \Twig\Node\EmptyNode::class,
        \Twig\Node\CaptureNode::class,
        \Twig\Node\ImportNode::class,
        \Twig\Node\MacroNode::class,
        // Expressions (top level)
        \Twig\Node\Expression\ConstantExpression::class,
        \Twig\Node\Expression\ArrayExpression::class,
        \Twig\Node\Expression\GetAttrExpression::class,
        \Twig\Node\Expression\FilterExpression::class,
        \Twig\Node\Expression\FunctionExpression::class,
        \Twig\Node\Expression\TestExpression::class,
        \Twig\Node\Expression\ConditionalExpression::class,
        \Twig\Node\Expression\NullCoalesceExpression::class,
        \Twig\Node\Expression\ParentExpression::class,
        \Twig\Node\Expression\BlockReferenceExpression::class,
        \Twig\Node\Expression\EmptyExpression::class,
        // Macro call (m.foo(...) after {% import _self as m %}) — TemplateLinter pins
        // the ONLY sanctioned import shape, so 'template' is always a self-reference.
        \Twig\Node\Expression\MacroReferenceExpression::class,
        // Variables
        \Twig\Node\Expression\Variable\ContextVariable::class,
        \Twig\Node\Expression\Variable\AssignContextVariable::class,
        \Twig\Node\Expression\Variable\LocalVariable::class,
        \Twig\Node\Expression\Variable\TemplateVariable::class,
        \Twig\Node\Expression\Variable\AssignTemplateVariable::class,
        // Unary (NO SpreadUnary)
        \Twig\Node\Expression\Unary\NegUnary::class,
        \Twig\Node\Expression\Unary\NotUnary::class,
        \Twig\Node\Expression\Unary\PosUnary::class,
        \Twig\Node\Expression\Unary\StringCastUnary::class,
        // Binary (NO Matches/HasEvery/HasSome/Set*/destructuring)
        \Twig\Node\Expression\Binary\AddBinary::class,
        \Twig\Node\Expression\Binary\AndBinary::class,
        \Twig\Node\Expression\Binary\BitwiseAndBinary::class,
        \Twig\Node\Expression\Binary\BitwiseOrBinary::class,
        \Twig\Node\Expression\Binary\BitwiseXorBinary::class,
        \Twig\Node\Expression\Binary\ConcatBinary::class,
        \Twig\Node\Expression\Binary\DivBinary::class,
        \Twig\Node\Expression\Binary\ElvisBinary::class,
        \Twig\Node\Expression\Binary\EndsWithBinary::class,
        \Twig\Node\Expression\Binary\EqualBinary::class,
        \Twig\Node\Expression\Binary\FloorDivBinary::class,
        \Twig\Node\Expression\Binary\GreaterBinary::class,
        \Twig\Node\Expression\Binary\GreaterEqualBinary::class,
        \Twig\Node\Expression\Binary\InBinary::class,
        \Twig\Node\Expression\Binary\LessBinary::class,
        \Twig\Node\Expression\Binary\LessEqualBinary::class,
        \Twig\Node\Expression\Binary\ModBinary::class,
        \Twig\Node\Expression\Binary\MulBinary::class,
        \Twig\Node\Expression\Binary\NotEqualBinary::class,
        \Twig\Node\Expression\Binary\NotInBinary::class,
        \Twig\Node\Expression\Binary\NotSameAsBinary::class,
        \Twig\Node\Expression\Binary\NullCoalesceBinary::class,
        \Twig\Node\Expression\Binary\OrBinary::class,
        \Twig\Node\Expression\Binary\PowerBinary::class,
        \Twig\Node\Expression\Binary\SameAsBinary::class,
        \Twig\Node\Expression\Binary\SpaceshipBinary::class,
        \Twig\Node\Expression\Binary\StartsWithBinary::class,
        \Twig\Node\Expression\Binary\SubBinary::class,
        \Twig\Node\Expression\Binary\XorBinary::class,
        // Tests (NO ConstantTest)
        \Twig\Node\Expression\Test\DefinedTest::class,
        \Twig\Node\Expression\Test\DivisiblebyTest::class,
        \Twig\Node\Expression\Test\EvenTest::class,
        \Twig\Node\Expression\Test\NullTest::class,
        \Twig\Node\Expression\Test\OddTest::class,
        \Twig\Node\Expression\Test\SameasTest::class,
        // Twig core implicitly wraps any `{% if expr %}` / `expr ? a : b` condition that
        // isn't already statically known boolean (e.g. a bare function call) in this node
        // (admin-contributed-templates spec §3 — is_preview()/color_mode_enabled()/
        // claim_priority_image() used as bare conditions by the shipped default theme).
        // Pure boolean coercion (with a Markup->string cast) — no code execution, no I/O.
        \Twig\Node\Expression\Test\TrueTest::class,
        // Filters (NO RawFilter)
        \Twig\Node\Expression\Filter\DefaultFilter::class,
        // Ternary
        \Twig\Node\Expression\Ternary\ConditionalTernary::class,
    ];

    public static function isAllowedNodeClass(string $class): bool
    {
        return in_array($class, self::NODE_CLASSES, true);
    }
}
