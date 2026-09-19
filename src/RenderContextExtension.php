<?php

declare(strict_types=1);

namespace Thallo\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Billing\PlanCheckoutUrlResolver;
use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Contracts\Style\RegionStyle;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Contracts\Style\Vocabulary;
use Thallo\Contracts\Content\BlockEditableFieldResolver;
use Thallo\Contracts\Content\FormSealer;
use Thallo\Contracts\Content\RegionReader;
use Thallo\Contracts\Content\RichHtmlSanitizer;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Delivery\EntryListReader;
use Thallo\Contracts\Delivery\FacetCountsReader;
use Thallo\Contracts\Delivery\MediaUrlResolver;
use Thallo\Contracts\Delivery\MediaVariantUrlResolver;
use Thallo\Contracts\Delivery\StorefrontLinkResolver;
use Thallo\Contracts\Delivery\StorefrontWishlistResolver;
use Thallo\Contracts\Settings\SiteFaviconProvider;
use Thallo\Contracts\Settings\SiteLogoProvider;
use Thallo\Contracts\Navigation\MenuReader;
use Thallo\Render\ActiveThemeSource;
use Thallo\Render\Templates\CustomCssUrl;
use Thallo\Render\Templates\IconSet;
use Thallo\Render\Theme\ThemeColors;
use Thallo\Render\Theme\ThemeDesign;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Thallo\Render\Style\ThemeStylesheetArtifact;
use Thallo\Render\Style\BlockStyleEmitter;
use Thallo\Render\Style\ClassNames;
use Thallo\Render\Style\CompiledStyleArtifacts;
use Thallo\Render\Style\ThemeStylesheetArtifacts;

/**
 * The theme-facing template functions. The extension is the per-render context object:
 * the controller sets the current locale before rendering (request-scoped in classic
 * PHP — deliberately not global static state).
 *
 *   menu(slug)      → MenuReader tree for the current locale; [] when navigation is
 *                     absent or disabled (render has NO hard dependency on it)
 *   path(entryUuid) → the entry's live public path; null unless published (a template
 *                     can never emit a dead link)
 *   asset(rel)      → /theme-assets/{rel}; rejects absolute URLs, .., leading /, and
 *                     backslashes with a template-facing error naming the value
 *   facets(type, field) → facet counts (preview spec §5); the result's cache tags go
 *                     into the render-scoped collector (reset by the controller before
 *                     EVERY render; drained after success) so facet pages purge
 *                     event-driven
 */
final class RenderContextExtension extends AbstractExtension
{
    private string $locale;

    /** @var array<string,string> render-scoped surrogate tags (see resetTags/drainTags) */
    private array $collectedTags = [];

    /** Render-scoped asset-context override (font spec §3): [base, assetsDir]. */
    private ?string $assetBase = null;
    private ?string $assetContextDir = null;

    /**
     * Nesting amendment §A2: mirrors the app-side BlockDepth::MAX (packs cannot
     * import app classes); an app-side test asserts the two agree.
     */
    public const MAX_BLOCK_DEPTH = 5;

    /** Render-scoped nesting depth (see resetBlockDepth). */
    private int $blockDepth = 0;

    /** Render-scoped priority-image claim (see claimPriorityImage/resetPriorityImageClaim). */
    private bool $priorityImageClaimed = false;

    /** Closed block-asset catalog (modern-blocks spec §1) — block_script() is
     *  DB-template vocabulary; only these names ever resolve to a script tag. */
    public const BLOCK_SCRIPT_ASSETS = ['animated-text', 'code', 'gallery'];

    /** @var array<string,bool> per-render emitted set (bandwidth dedupe only —
     *  the asset's own exactly-once IIFE guard is the correctness authority). */
    private array $emittedBlockScripts = [];

    /**
     * Preview-only block annotation (visual-canvas spec §2): when on, blocks()
     * wraps each rendered instance in a layout-inert `.thallo-preview-block`
     * carrier so the canvas bridge can map DOM to block ids. Reset-family: the
     * controller ASSIGNS it before every render; never on for live renders.
     */
    private bool $annotateBlocks = false;

    /**
     * Preview-only appearance override (theme-color-config spec §6): request-local,
     * reset before every render by the controller. Null = use the saved/default
     * source; a verified preview session's signed pair sets it for that render only.
     */
    /** The theme whose artifact theme_stylesheet_url() names: bound by TwigFactory / the render. */
    private ?ThemeLocator $boundTheme = null;

    private ?string $appearanceAccentOverride = null;
    private ?string $appearanceNeutralOverride = null;

    /**
     * Per-block frame stack (edit-in-place spec §2): pushed around each block
     * template render so safe_html knows WHICH block instance it is emitting
     * for — and whether that block is prose (editable_field non-null). A stack,
     * not a scalar: nested blocks() calls run inside parent templates.
     * Reset-family (resetBlockFrames): cleared before every render.
     *
     * @var list<array{id: mixed, editable_field: ?string}>
     */
    private array $blockFrames = [];

    /** @var array<string,bool> block types already logged this process (log ONCE per type) */
    private array $loggedBlockMisses = [];

    /**
     * Render-time re-validation of an authored `plan_key` (pricing-bridge spec §5.4).
     * The editor's own schema enforces the IDENTICAL pattern at save time (block-library
     * spec §5's `pattern` constraint) — this is defense in depth, never trusted alone:
     * data can reach the template already saved (an older row, a direct API write)
     * without ever passing through the editor's validator.
     */
    private const PLAN_KEY_PATTERN = '/\A[a-z0-9._-]{1,100}\z/';

    public function __construct(
        private readonly ?MenuReader $menus,
        private readonly EntryTargetResolver $targets,
        string $defaultLocale = 'en',
        private readonly ?FacetCountsReader $facetReader = null,
        // Provider-injected (block-builder spec §6) — NEVER read from Twig context.
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
        /** Soft-bound (sanitizer spec §4): null → safe_html fails CLOSED (escapes). */
        private readonly ?RichHtmlSanitizer $htmlSanitizer = null,
        /** Soft-bound (starter-library spec §3): null → media() always returns null. */
        private readonly ?MediaUrlResolver $mediaUrls = null,
        /** Soft-bound (edit-in-place spec §2): null → safe_html never marks. */
        private readonly ?BlockEditableFieldResolver $editableFields = null,
        /** Soft-bound (block-library spec §2): null → site_logo() returns null. */
        private readonly ?SiteLogoProvider $siteLogo = null,
        /** Pack-internal (icon-library spec): null → icon() returns null. */
        private readonly ?IconSet $icons = null,
        /** Soft-bound (global-regions spec): null → region_blocks() returns null → fallback chrome. */
        private readonly ?RegionReader $regions = null,
        /** Soft-bound (site-identity spec): null → site_favicon() returns null. */
        private readonly ?SiteFaviconProvider $favicon = null,
        /** Pack-internal (custom-css spec): null → custom_css() returns null. */
        private readonly ?CustomCssUrl $customCssUrl = null,
        /** Pack-internal (theme-setting spec §3): null → no asset cache-buster. */
        private readonly ?ActiveThemeSource $themeSource = null,
        /** color-mode spec §3.4: false → no resolver, no marker, toggle renders nothing. */
        private readonly bool $colorModeEnabled = true,
        /** theme-color-config spec §4: null → default blue/slate (no override emitted). */
        private readonly ?ThemeAppearanceSource $appearance = null,
        /**
         * Active theme's assets dir (theme-setting spec §3 P1): null → no content
         * fingerprint. Lets asset() append a per-file `&v=<mtime>` so a theme-asset
         * EDIT busts the 24h browser cache immediately — the `?t=` theme buster only
         * fires on a theme SWITCH, not an in-place edit.
         */
        private readonly ?string $themeAssetsDir = null,
        /** Soft-bound (blog-posts spec): null → entries() returns [] (block renders nothing). */
        private readonly ?EntryListReader $entryReader = null,
        /**
         * Soft-bound (form-block spec §4): null → form_render() returns null, so the
         * form block renders its disabled notice (never a partial/insecure form).
         */
        private readonly ?FormSealer $formSealer = null,
        /**
         * Soft-bound (Commerce-Slice-2 Fix A): null → shop_product_url()/shop_category_url()/
         * shop_index_url() all return null, so a block's no-JS fallback degrades to plain text
         * instead of a link — never a fatal error when commerce isn't installed/active.
         */
        private readonly ?StorefrontLinkResolver $storefrontLinks = null,
        /**
         * Soft-bound (storefront-performance spec §3): null → media_image() has no MIME
         * knowledge and degrades to media()'s plain URL with srcset null.
         */
        private readonly ?MediaVariantUrlResolver $mediaVariants = null,
        /**
         * Soft-bound (storefront-v1 spec §5): null → shop_wishlist_scope()/shop_wishlist_url()
         * both return null, so wishlist affordances simply disappear when commerce isn't
         * installed/active — never a fatal error.
         */
        private readonly ?StorefrontWishlistResolver $wishlist = null,
        /**
         * Soft-bound (pricing-bridge spec §5.4): null → plan_checkout_url() always
         * returns null, so the pricing_plan block's CTA falls back to the authored
         * button_url unchanged.
         */
        private readonly ?PlanCheckoutUrlResolver $planCheckoutUrls = null,
        /**
         * ONLY needed for planCheckoutUrl()'s resolve() call — every other soft-bound
         * resolver in this class is ApplicationContext-free. Named `$appContext` (not
         * `$context`) to stay unambiguous against the many methods below whose OWN
         * `$context` parameter is the per-render Twig context array, an entirely
         * different thing. Optional: constructions that never touch
         * plan_checkout_url() (essentially every existing test) need not supply it,
         * and the function degrades to null exactly as if the resolver itself were
         * unbound.
         */
        private readonly ?ApplicationContext $appContext = null,
        /** Layered delivery (visual builder spec §2.2–2.4): the theme artifact per theme. */
        private readonly ?ThemeStylesheetArtifacts $themeArtifacts = null,
        /** The compiled style artifact per theme (visual builder spec §2.4). */
        private readonly ?CompiledStyleArtifacts $compiledArtifacts = null,
        /** Block style declarations (spec §1.7): soft-bound; null = no block declares targets. */
        private readonly ?BlockStyleRegistry $styleRegistry = null,
        private readonly BlockStyleEmitter $styleEmitter = new BlockStyleEmitter(),
        /** The site's style classes (visual builder spec §4.3): soft-bound; null = no class layer. */
        private readonly ?StyleClassProvider $styleClasses = null,
    ) {
        $this->locale = $defaultLocale;
    }

    /** The generation of the style class snapshot this request renders from (spec §4.3). */
    public function styleSnapshotGeneration(): int
    {
        return $this->styleClasses?->snapshot()->generation ?? 0;
    }

    /**
     * The cascade layers for a block's ordered `settings.classes`, from the request's snapshot.
     *
     * @param mixed $ids
     * @return list<array{id: string, style: array<string,mixed>}>
     */
    private function classRefsFor(mixed $ids): array
    {
        if ($this->styleClasses === null || !is_array($ids) || $ids === []) {
            return [];
        }
        return $this->styleClasses->snapshot()->refsFor(array_values(array_filter($ids, 'is_string')));
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('menu', $this->menu(...)),
            new TwigFunction('path', $this->path(...)),
            new TwigFunction('asset', $this->asset(...)),
            new TwigFunction('facets', $this->facets(...)),
            new TwigFunction('entries', $this->entries(...)),
            new TwigFunction('is_preview', $this->isPreview(...)),
            new TwigFunction('blocks', $this->blocks(...), [
                'needs_environment' => true,
                'needs_context' => true,
                'is_safe' => ['html'],
            ]),
            new TwigFunction('media', $this->media(...)),
            new TwigFunction('media_image', $this->mediaImage(...)),
            new TwigFunction('claim_priority_image', $this->claimPriorityImage(...), ['needs_context' => true]),
            new TwigFunction('site_logo', $this->siteLogo(...)),
            new TwigFunction('video_embed', $this->videoEmbed(...)),
            new TwigFunction('icon', $this->icon(...)), // NO is_safe — safety travels in the Markup value
            // NO is_safe — the layout assigns the result via {% set %}, where
            // compile-time safety is lost; Markup carries safety in the VALUE
            // (the icon() lesson) while a null still falls through cleanly.
            new TwigFunction('region_blocks', $this->regionBlocks(...), [
                'needs_environment' => true,
                'needs_context' => true,
            ]),
            new TwigFunction('region_settings', $this->regionSettings(...)),
            new TwigFunction('region_style_classes', $this->regionStyleClasses(...)),
            new TwigFunction('site_favicon', $this->siteFavicon(...)),
            new TwigFunction('custom_css', $this->customCss(...)),
            new TwigFunction('color_mode_enabled', $this->colorModeEnabled(...)),
            new TwigFunction('runtime_script', $this->runtimeScript(...)),
            new TwigFunction('block_script', $this->blockScript(...)),
            // is_safe html: trusted, static, theme-owned resolver (mirrors icon()).
            new TwigFunction('color_mode_script', $this->colorModeScript(...), ['is_safe' => ['html']]),
            // is_safe html: generated purely from the closed accent/neutral enums.
            new TwigFunction('theme_colors_style', $this->themeColorsStyle(...), ['is_safe' => ['html']]),
            // No is_safe: returns an array whose members carry their own safety (P2a).
            new TwigFunction('theme_style_scope', $this->themeStyleScope(...)),
            // form-block spec §4/§6: ONE narrow function — derives+seals in a single
            // pass and returns the render array; no re-open, no extra sandbox surface.
            new TwigFunction('form_render', $this->formRender(...), ['needs_context' => true]),
            // Commerce-Slice-2 Fix A: soft-bound storefront link helpers for a block's no-JS
            // `<noscript>` fallback (see the $storefrontLinks constructor doc). All null-safe.
            new TwigFunction('shop_product_url', $this->shopProductUrl(...)),
            new TwigFunction('shop_category_url', $this->shopCategoryUrl(...)),
            new TwigFunction('shop_index_url', $this->shopIndexUrl(...)),
            new TwigFunction('json_script', $this->jsonScript(...)),
            // Layered delivery (visual builder spec §2.3): the layer-order sheet and the theme
            // artifact the head links instead of individual theme files.
            new TwigFunction('layers_stylesheet_url', $this->layersStylesheetUrl(...)),
            new TwigFunction('theme_stylesheet_url', $this->themeStylesheetUrl(...)),
            new TwigFunction('settings_stylesheet_url', $this->settingsStylesheetUrl(...)),
            // Style targets (visual builder spec §2.5): a block template styles its declared
            // targets through these; nothing else turns a setting into markup.
            new TwigFunction('style_classes', $this->styleClasses(...)),
            new TwigFunction('style_attrs', $this->styleAttrs(...), ['is_safe' => ['html']]),
            new TwigFunction('token_class', $this->tokenClass(...)),
            // Slot geometry (visual builder spec §5.4): the element a template renders a blocks
            // field into names its slot in canvas mode, so the bridge derives drop zones from
            // real slot elements, never the display-contents wrappers.
            new TwigFunction('slot_attrs', $this->slotAttrs(...), ['is_safe' => ['html']]),
            new TwigFunction('is_canvas', fn (): bool => $this->annotateBlocks),
            // Storefront-v1 spec §5: soft-bound wishlist seam (see the $wishlist constructor
            // doc). Both null-safe — capability off or seam unbound means null, never a throw.
            new TwigFunction('shop_wishlist_scope', $this->shopWishlistScope(...)),
            new TwigFunction('shop_wishlist_url', $this->shopWishlistUrl(...)),
            // is_safe html: every attribute value is ENT_QUOTES-escaped and every URL
            // passes safeUrl() inside seoHead() itself (seo-head spec §3).
            new TwigFunction('seo_head', $this->seoHead(...), [
                'is_safe' => ['html'],
                'needs_context' => true,
            ]),
            // is_safe html: every dynamic value is escaped for its exact sink inside
            // fontFacesStyle() itself (default-theme-font spec §3).
            new TwigFunction('font_faces_style', $this->fontFacesStyle(...), ['is_safe' => ['html']]),
            // Pricing-bridge spec §5.4: soft-bound; null on ANY of unbound resolver,
            // missing ApplicationContext, absent/malformed key, or the resolver itself
            // answering null — the pricing_plan block falls back to its authored
            // button_url on every one of those, indistinguishably.
            new TwigFunction('plan_checkout_url', $this->planCheckoutUrl(...)),
        ];
    }

    /**
     * The catalog product URL for `$slug` (Commerce-Slice-2 Fix A), or null when either the
     * slug is blank/absent or no {@see StorefrontLinkResolver} is bound (commerce not
     * installed/active) — a block template falls back to plain text on null, never a broken
     * `href=""`.
     */
    /**
     * The fingerprinted storefront stylesheet URL, or null when commerce is inactive/unbound.
     * The theme links this from `<head>`: block templates emit the uncacheable
     * `/_shop/assets/shop.css` ALIAS (which 302s) inside the body, so without this the
     * storefront's own header chrome paints unstyled and restyles on EVERY navigation.
     */
    /** Bind the theme whose artifact theme_stylesheet_url() names (TwigFactory and the render). */
    public function bindTheme(?ThemeLocator $theme): void
    {
        $this->boundTheme = $theme;
    }

    /**
     * The layer-order stylesheet (`@layer theme, settings;`), loaded first; versioned by its
     * own content so an edit busts the immutable cache.
     */
    public function layersStylesheetUrl(): string
    {
        static $version = null;
        $version ??= substr(sha1((string) file_get_contents(dirname(__DIR__) . '/assets/style/layers.css')), 0, 8);
        return '/_thallo/layers.css?v=' . $version;
    }

    /**
     * The layered theme artifact for the bound theme: every manifest stylesheet and every
     * contributed package stylesheet inside `@layer theme`, content-fingerprinted (spec §2.4).
     * Under a preview asset base the same file name serves from the preview theme.
     */
    public function themeStylesheetUrl(): string
    {
        if ($this->boundTheme === null || $this->themeArtifacts === null) {
            throw new RuntimeError('theme_stylesheet_url(): no theme is bound to the render context.');
        }
        $hash = $this->themeArtifacts->forTheme($this->boundTheme)->hash;
        return ($this->assetBase ?? '/theme-assets') . '/' . ThemeStylesheetArtifact::fileName($hash);
    }

    /**
     * The compiled style artifact for the bound theme: `@layer settings` with the `--t-*`
     * custom properties and every utility the vocabulary yields, content-fingerprinted (spec
     * §2.4). Linked after the theme artifact; served from the same asset base.
     */
    public function settingsStylesheetUrl(): string
    {
        if ($this->boundTheme === null || $this->compiledArtifacts === null) {
            throw new RuntimeError('settings_stylesheet_url(): no theme is bound to the render context.');
        }
        $hash = $this->compiledArtifacts->forTheme($this->boundTheme)['hash'];
        return ($this->assetBase ?? '/theme-assets') . '/' . CompiledStyleArtifacts::fileName($hash);
    }

    /**
     * The utility classes the current block's settings resolve to for `$target` (spec §2.5),
     * with a leading space so it drops straight after a template's own class list. Empty
     * outside a block, for a type without declared targets, and when nothing is set.
     *
     * @throws RuntimeError for a target the block type does not declare (the lint refuses
     *         it at save; a declaration edited out from under a template still fails loudly)
     */
    public function styleClasses(string $target): string
    {
        [$frame, $targets] = $this->styleFrame($target);
        if ($frame === null || $targets === null) {
            return '';
        }
        $classes = $this->styleEmitter->classesFor(
            $frame['settings'],
            $targets,
            $target,
            $this->classRefsFor($frame['settings']['classes'] ?? null),
        );
        return $classes === [] ? '' : ' ' . implode(' ', $classes);
    }

    /** `data-thallo-slot="<field>"` in canvas mode, nothing otherwise (spec §5.4); leading space. */
    public function slotAttrs(string $slot): string
    {
        if (!$this->annotateBlocks) {
            return '';
        }
        return ' data-thallo-slot="' . htmlspecialchars($slot, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }

    /** The attributes `$target` owns (anchor, `data-*`, accessibility label), escaped, leading space. */
    public function styleAttrs(string $target): string
    {
        [$frame, $targets] = $this->styleFrame($target);
        if ($frame === null || $targets === null) {
            return '';
        }
        $out = '';
        foreach ($this->styleEmitter->attrsFor($frame['settings'], $targets, $target) as $name => $value) {
            $out .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $out;
    }

    /**
     * The utility class for a `token` or `choice` field a block keeps in `data` (spec §1.7):
     * the same class the compiler emits for the property, leading space; '' for an absent or
     * unknown value so a stale stored value never reaches the class attribute.
     */
    public function tokenClass(string $property, mixed $value): string
    {
        $def = StyleSchema::property($property);
        if ($def === null) {
            throw new RuntimeError("token_class(): unknown style property \"{$property}\".");
        }
        if (!is_string($value) || $value === '') {
            return '';
        }
        $valid = $def->tokenDomain !== null
            ? str_starts_with($value, $def->tokenDomain . '.')
                && in_array(substr($value, strlen($def->tokenDomain) + 1), Vocabulary::names($def->tokenDomain), true)
            : in_array($value, $def->choices ?? [], true);
        return $valid ? ' ' . ClassNames::for($property, $value) : '';
    }

    /**
     * @return array{0: array{type: string, settings: array<string,mixed>}|null, 1: StyleTargets|null}
     */
    private function styleFrame(string $target): array
    {
        if ($this->blockFrames === [] || $this->styleRegistry === null) {
            return [null, null];
        }
        $frame = $this->blockFrames[count($this->blockFrames) - 1];
        $targets = $this->styleRegistry->targetsFor($frame['type']);
        if ($targets === null) {
            return [null, null];
        }
        if (!in_array($target, $targets->names(), true)) {
            throw new RuntimeError(sprintf(
                'Style target "%s" is not declared by block type "%s".',
                $target,
                $frame['type'],
            ));
        }
        return [$frame, $targets];
    }

    public function shopProductUrl(?string $slug): ?string
    {
        if ($this->storefrontLinks === null || $slug === null || $slug === '') {
            return null;
        }
        return $this->storefrontLinks->productUrl($slug);
    }

    /** The catalog category URL for `$slug` — same null rules as {@see self::shopProductUrl()}. */
    public function shopCategoryUrl(?string $slug): ?string
    {
        if ($this->storefrontLinks === null || $slug === null || $slug === '') {
            return null;
        }
        return $this->storefrontLinks->categoryUrl($slug);
    }

    /** The shop index ("browse all products") URL, or null when commerce isn't bound. */
    public function shopIndexUrl(): ?string
    {
        return $this->storefrontLinks?->shopIndexUrl();
    }

    /**
     * The opaque wishlist device-storage scope (storefront-v1 spec §5), or null when the seam
     * is unbound (commerce not installed/active) or the surface itself answers null (capability
     * off) — templates emit no wishlist affordance on null.
     */
    public function shopWishlistScope(): ?string
    {
        return $this->wishlist?->storageScope();
    }

    /** The canonical wishlist page URL — same null rules as {@see self::shopWishlistScope()}. */
    public function shopWishlistUrl(): ?string
    {
        return $this->wishlist?->wishlistUrl();
    }

    /**
     * The pricing_plan block's checkout deep link (pricing-bridge spec §5.4). Null on
     * ANY of: no resolver bound, no {@see ApplicationContext} available, an
     * absent/blank/malformed key, or the resolver itself answering null (capability
     * off, engine unavailable, no configured admin origin — see {@see
     * \Thallo\Subscriptions\Bridge\AdminBillingPlanCheckoutUrlResolver}, which this
     * pack never imports). Every one of those degrades IDENTICALLY from the
     * template's point of view: the CTA falls back to the authored `button_url`.
     *
     * A well-formed but UNKNOWN key still resolves to a real URL — this function makes
     * no existence promise about `$planKey`; that is the resolver's contract, not a
     * render-time catalog query this pack must never perform.
     */
    public function planCheckoutUrl(?string $planKey): ?string
    {
        if ($this->planCheckoutUrls === null || $this->appContext === null) {
            return null;
        }
        if (!is_string($planKey) || preg_match(self::PLAN_KEY_PATTERN, $planKey) !== 1) {
            return null;
        }
        return $this->planCheckoutUrls->resolve($this->appContext, $planKey);
    }

    /**
     * Safe JSON-for-<script> emitter (admin-contributed-templates spec §3): the ONE
     * sanctioned way to put structured data inside a script element. JSON_HEX_TAG makes
     * a literal "</script>" unrepresentable in the output — breakout is impossible —
     * and hex-encoded quotes/ampersands keep the payload inert. Fail-closed: an
     * unencodable value throws (JsonException) into the render error ladder; this never
     * emits partial or unsafe output. Returns Markup — safety travels in the value, so
     * templates write {{ json_script(data) }} with no |raw.
     */
    public function jsonScript(mixed $value): Markup
    {
        $json = json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_UNESCAPED_SLASHES,
        );
        return new Markup($json, 'UTF-8');
    }

    /**
     * The site custom stylesheet's versioned URL (custom-css spec §4), or null
     * when no non-empty DB row exists — the layout emits no link on null.
     */
    public function customCss(): ?string
    {
        return $this->customCssUrl?->url();
    }

    /** Color-mode enablement (color-mode spec §3.4): gates the resolver, the marker, and the toggle block. */
    public function colorModeEnabled(): bool
    {
        return $this->colorModeEnabled;
    }

    /** Stable logical URL of the package theme runtime (theme-runtime spec §2.3). */
    public function runtimeScript(): string
    {
        return '/_thallo/runtime/runtime.js';
    }

    /**
     * Deferred script tag for a per-block runtime asset (modern-blocks spec §1):
     * closed catalog, once per render per name. Markup return — the tag is the
     * value; autoescape never mangles it. Fragment renders reset independently
     * (EntryBlocksRenderer), so a page may carry a duplicate tag — safe, because
     * each asset self-guards.
     */
    public function blockScript(string $name): \Twig\Markup
    {
        if (!in_array($name, self::BLOCK_SCRIPT_ASSETS, true) || isset($this->emittedBlockScripts[$name])) {
            return new \Twig\Markup('', 'UTF-8');
        }
        $this->emittedBlockScripts[$name] = true;
        return new \Twig\Markup(
            '<script defer src="/_thallo/runtime/block-' . $name . '.js"></script>',
            'UTF-8',
        );
    }

    /** The verbatim no-flash resolver (color-mode spec §3.1), or empty markup when disabled. */
    public function colorModeScript(): \Twig\Markup
    {
        $html = $this->colorModeEnabled ? \Thallo\Render\ColorMode::scriptTag() : '';
        return new \Twig\Markup($html, 'UTF-8');
    }

    /** Preview-only appearance override (reset before every render by the controller). */
    public function setThemeAppearanceOverride(?string $accent, ?string $neutral): void
    {
        $this->appearanceAccentOverride = $accent;
        $this->appearanceNeutralOverride = $neutral;
    }

    /**
     * Theme-color-config spec §5: emit the token override for the effective pair —
     * a preview override (request-local) beats the saved/default source. Emits
     * NOTHING for the default pair (site.css already carries blue/slate). Generated
     * purely from the closed enums, so it is html-safe.
     */
    public function themeColorsStyle(): \Twig\Markup
    {
        $accent = $this->appearanceAccentOverride
            ?? $this->appearance?->accent()
            ?? ThemeColors::DEFAULT_ACCENT;
        $neutral = $this->appearanceNeutralOverride
            ?? $this->appearance?->neutral()
            ?? ThemeColors::DEFAULT_NEUTRAL;

        // Normalize (a preview override could be junk) — invalid → default.
        $accent = ThemeColors::normalizeAccent($accent) ?? ThemeColors::DEFAULT_ACCENT;
        $neutral = ThemeColors::normalizeNeutral($neutral) ?? ThemeColors::DEFAULT_NEUTRAL;

        // Design tokens (website plan phase 1b) ride in the same block, after the colours.
        $css = ThemeColors::css($accent, $neutral) . ThemeDesign::css(
            $this->appearance?->radius() ?? ThemeDesign::DEFAULT_RADIUS,
            $this->appearance?->font() ?? ThemeDesign::DEFAULT_FONT,
            $this->appearance?->background() ?? ThemeDesign::DEFAULT_BACKGROUND,
            $neutral,
        );
        return new \Twig\Markup($css === '' ? '' : "<style>{$css}</style>", 'UTF-8');
    }

    /**
     * Style-block spec §4.2: the effective scoped skin for a `style` block instance.
     * Returns a class fragment (leading space, '' when no re-skin) and the inline
     * <style> Markup ('' when no re-skin). Follows the global color mode. BOTH members
     * are Twig\Markup: the class is enum-derived (closed families → safe by
     * construction), so it is emitted as-is rather than relying on autoescape being a
     * no-op (review P2a). The <style> carries its own safety.
     *
     * @return array{class: \Twig\Markup, style: \Twig\Markup}
     */
    public function themeStyleScope(?string $accent, ?string $neutral): array
    {
        $class = ThemeColors::skinClass($accent, $neutral);
        $css = $class === '' ? '' : ThemeColors::scopedCss($accent, $neutral, $class);
        return [
            'class' => new \Twig\Markup($class === '' ? '' : ' ' . $class, 'UTF-8'),
            'style' => new \Twig\Markup($css === '' ? '' : "<style>{$css}</style>", 'UTF-8'),
        ];
    }

    /**
     * Bounded numeric-clamp helper (gate-audit amendment, admin-contributed-templates
     * task 7): replaces the |matches "/^[0-9]+(\.[0-9]+)?$/" + max()/min() pair
     * blocks/style.twig used directly for the shadow-opacity CSS variable. Null (not 0)
     * on a non-numeric value — the caller distinguishes "no value" from "clamped to the
     * floor" the same way the removed matches check did.
     */
    public function numericClamp(mixed $value, float $min, float $max): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        return max($min, min($max, (float) $value));
    }


    /**
     * The ONE region render path (global-regions spec §10): resolves the
     * region and composes it through the real blocks() machinery with canvas
     * annotation and edit-in-place marking suppressed for the subtree —
     * chrome block ids are not entry blocks; annotated wrappers would corrupt
     * the canvas DOM↔id bridge. Suppression is render-context state inside
     * this helper; blocks() keeps its public signature and no annotation
     * toggle leaks into templates. Null for EVERY unavailable state — reader
     * unbound, region absent, saved empty (the reader folds absent/empty;
     * unbound folds here) — so templates render fallback chrome on null;
     * hiding is _presentation's decision.
     *
     * @param array<string,mixed> $context
     */
    public function regionBlocks(Environment $env, array $context, string $slug): ?\Twig\Markup
    {
        $list = $this->regions?->blocks($slug);
        if ($list === null || $list === []) {
            return null;
        }
        $saved = $this->annotateBlocks;
        $this->annotateBlocks = false;
        try {
            // Thread the region slug so a form placed in a region gets a stable,
            // region-scoped source key (form-block spec §5).
            $html = $this->blocks($env, ['region_slug' => $slug] + $context, $list);
        } finally {
            $this->annotateBlocks = $saved;
        }
        return new \Twig\Markup($html, 'UTF-8');
    }

    /** @return array<string,mixed> */
    public function regionSettings(string $slug): array
    {
        return $this->regions?->settings($slug) ?? [];
    }

    /**
     * The utility classes a region's own style (its Style tab) puts on `$target` — `root`, the
     * bar, or `inner`, its content (RegionStyle) — with a leading space; '' for an unstyled region,
     * so an untouched header renders the classes it always had.
     *
     * `$settings` overrides the stored ones: the admin's chrome preview renders settings that were
     * POSTED, not saved, and hands them over.
     *
     * @param array<string,mixed>|null $settings
     * @throws RuntimeError for a target a region does not have
     */
    public function regionStyleClasses(string $slug, string $target = 'root', ?array $settings = null): string
    {
        $targets = RegionStyle::targets();
        if (!in_array($target, $targets->names(), true)) {
            throw new RuntimeError("region_style_classes(): a region has no \"{$target}\" target.");
        }
        $style = ($settings ?? $this->regionSettings($slug))['style'] ?? null;
        if (!is_array($style) || $style === []) {
            return '';
        }
        $classes = $this->styleEmitter->classesFor(['style' => $style], $targets, $target);
        return $classes === [] ? '' : ' ' . implode(' ', $classes);
    }

    /**
     * Render payload for a `form` block (form-block spec §4/§6): ONE derive+seal
     * pass via the app-bound FormSealer, reading fields/honeypot/key straight off
     * the returned SealedForm — the encrypted token is never re-opened in the render
     * path. Null means "not a form block", "un-routable/underivable", or "forms
     * unavailable" → the template renders the disabled notice. needs_context so the
     * source identity can key off entry/current_path/region_slug.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $block
     * @return array<string,mixed>|null
     */
    public function formRender(array $context, array $block): ?array
    {
        if ($this->formSealer === null || ($block['type'] ?? null) !== 'form') {
            return null; // gated: only the form block may seal a descriptor
        }
        $entry = is_array($context['entry'] ?? null) ? $context['entry'] : null;
        $path = is_string($context['current_path'] ?? null) ? $context['current_path'] : null;
        $region = is_string($context['region_slug'] ?? null) ? $context['region_slug'] : null;
        $sealed = $this->formSealer->describe($block, $entry, $path, $region);
        if ($sealed === null) {
            return null; // un-routable / underivable → disabled notice
        }
        $d = $sealed->descriptor;
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        $submit = is_string($data['submit_label'] ?? null) && $data['submit_label'] !== ''
            ? $data['submit_label'] : 'Send';
        // Submit button style mirrors the button block's variant/color vocabulary; the
        // template maps these to the shared button classes (unknown values degrade there).
        return [
            'token' => $sealed->token,
            'key' => $d->formKey,
            'honeypot' => $d->honeypotField,
            // Untyped closure: the render pack must not import the app's FieldDef VO.
            'fields' => array_map(static fn ($f): array => $f->toArray(), $d->fields),
            'heading' => is_string($data['heading'] ?? null) ? $data['heading'] : null,
            'intro' => is_string($data['intro'] ?? null) ? $data['intro'] : null,
            'submit_label' => $submit,
            'submit_variant' => is_string($data['submit_variant'] ?? null) ? $data['submit_variant'] : 'solid',
            'submit_color' => is_string($data['submit_color'] ?? null) ? $data['submit_color'] : 'primary',
            'success_message' => $d->successMessage,
        ];
    }

    /**
     * Vendored inline icon (icon-library spec): Lucide by default,
     * `brand:{name}` for the curated Simple Icons set. Returns Markup — NOT an
     * is_safe string — so `{{ icon(x) ?? x }}` renders the trusted SVG raw
     * while the untrusted string fallback stays auto-escaped. Null for any
     * invalid or unknown name so templates can fall back to text.
     */
    public function icon(?string $name): ?\Twig\Markup
    {
        $svg = $name === null || $name === '' ? null : $this->icons?->svg($name);
        return $svg === null ? null : new \Twig\Markup($svg, 'UTF-8');
    }

    /**
     * The configured site logo as a public media URL (block-library spec §2):
     * provider-injected settings read resolved through media() — null when
     * unset or unresolvable, so the logo block falls back to the site name.
     *
     * $variant (site-identity spec §2, P2 pin): a CLOSED vocabulary at the
     * template boundary — null|'light'|'dark' only; anything else returns
     * null, so DB templates can never turn the argument into an unbounded
     * settings lookup. 'dark' unset → null → templates fall back to light.
     */
    public function siteLogo(?string $variant = null): ?string
    {
        $variant ??= 'light';
        if (!in_array($variant, ['light', 'dark'], true)) {
            return null;
        }
        $uuid = $this->siteLogo?->siteLogoUuid($variant);
        return $uuid === null ? null : $this->media($uuid);
    }

    /**
     * The configured favicon as a public media URL (site-identity spec §2,
     * P1 pin): the SAME media() predicate as everything else — uploads
     * disabled, non-anonymous access, or a non-public blob yield null, so
     * the layout emits NO link tag rather than one that 401s (favicon
     * fetches are anonymous browser requests).
     */
    public function siteFavicon(): ?string
    {
        $uuid = $this->favicon?->faviconUuid();
        return $uuid === null ? null : $this->media($uuid);
    }

    /**
     * Server-parsed video embed descriptor (block-library spec §2): strict
     * YouTube/Vimeo URL shapes only — templates build the iframe themselves
     * from a fixed pattern, so raw user iframes are never emitted. Anything
     * unparseable returns null and the video block renders nothing.
     *
     * @return array{provider: string, id: string}|null
     */
    public function videoEmbed(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $path = (string) ($parts['path'] ?? '');

        if ($host === 'youtube.com' || $host === 'youtube-nocookie.com' || $host === 'm.youtube.com') {
            $id = null;
            if ($path === '/watch') {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('#\\A/(?:shorts|embed)/([A-Za-z0-9_-]{6,20})\\z#', $path, $m) === 1) {
                $id = $m[1];
            }
            return is_string($id) && preg_match('/\\A[A-Za-z0-9_-]{6,20}\\z/', $id) === 1
                ? ['provider' => 'youtube', 'id' => $id]
                : null;
        }
        if ($host === 'youtu.be' && preg_match('#\\A/([A-Za-z0-9_-]{6,20})\\z#', $path, $m) === 1) {
            return ['provider' => 'youtube', 'id' => $m[1]];
        }
        if (
            ($host === 'vimeo.com' || $host === 'player.vimeo.com')
            && preg_match('#\\A/(?:video/)?(\\d{6,12})\\z#', $path, $m) === 1
        ) {
            return ['provider' => 'vimeo', 'id' => $m[1]];
        }
        return null;
    }

    /**
     * Uploaded-media URL for templates (starter-library spec §3): public +
     * anonymously retrievable blobs only (cached pages must never embed expiring
     * signed URLs). Null-safe on every failure — templates skip the element.
     */
    public function media(string $uuid): ?string
    {
        return $this->mediaUrls?->url($uuid);
    }

    /**
     * The ONE image-slot helper (spec §3). Resolver absent → media()'s plain URL (no MIME
     * knowledge; today's behavior). Resolver present → its three pinned outcomes verbatim,
     * including NULL for non-image blobs (never a media() fallback that would emit
     * <img src="…pdf">).
     *
     * @param list<int> $widths
     * @return array{src: string, srcset: ?string}|null
     */
    public function mediaImage(string $uuid, array $widths): ?array
    {
        $widths = self::normalizeWidths($widths);
        if ($this->mediaVariants === null) {
            $src = $this->media($uuid);
            return $src === null ? null : ['src' => $src, 'srcset' => null];
        }
        return $this->mediaVariants->variants($uuid, $widths);
    }

    /**
     * Defensive width normalization (admin-contributed-templates spec §3): media_image is
     * DB-template-callable, so the width list is attacker-shaped — positive ints only,
     * deduplicated, at most 8 candidates BEFORE any resolver work. TemplatePolicy separately
     * denies both range() and RangeBinary, which could allocate an unbounded array before this
     * method is entered.
     *
     * @param array<mixed> $widths
     * @return list<int>
     */
    public static function normalizeWidths(array $widths): array
    {
        $clean = [];
        foreach ($widths as $w) {
            if (is_int($w) && $w > 0 && !in_array($w, $clean, true)) {
                $clean[] = $w;
                if (count($clean) === 8) {
                    break;
                }
            }
        }
        return $clean;
    }

    /** @return list<TwigFilter> */
    public function getFilters(): array
    {
        return [
            // is_safe is justified ONLY because every path out of safeHtml() is
            // already safe: sanitized markup or pre-escaped text (sanitizer spec §4).
            new TwigFilter('safe_html', $this->safeHtml(...), ['is_safe' => ['html']]),
            // Theme-declared editable text (editable-string-fields spec §1):
            // is_safe html because annotated mode emits a marker span — so the
            // filter ESCAPES the value itself in BOTH modes (never autoescape).
            new TwigFilter('editable_text', $this->editableText(...), ['is_safe' => ['html']]),
            new TwigFilter('safe_url', $this->safeUrl(...)),
            // A bounded PHP helper (gate-audit amendment, task 7): the animated text's interval.
            new TwigFilter('numeric_clamp', $this->numericClamp(...)),
            new TwigFilter('br_tokens', $this->brTokens(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Author-typed line breaks (animated-text follow-up, 2026-08): the literal
     * tokens <br>, <br/>, <br /> become real breaks; EVERYTHING else stays
     * escaped — recognition happens on the escaped text, so author markup never
     * goes live. Accepts a Markup (e.g. editable_text output, already escaped
     * with a trusted wrapper) and then only rewrites the escaped tokens.
     */
    public function brTokens(mixed $value): \Twig\Markup
    {
        $s = $value instanceof \Twig\Markup
            ? (string) $value
            : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        return new \Twig\Markup(
            str_replace(['&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'], '<br>', $s),
            'UTF-8',
        );
    }

    /**
     * Scheme-allowlisted link value (starter-library spec §4): Twig autoescape does
     * NOT make href="javascript:…" safe. Allows site-relative paths (never //
     * protocol-relative — they smuggle a host), https, http, and mailto; everything
     * else nulls and templates render the label as plain text instead of a link.
     */
    public function safeUrl(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $url = trim($value);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }
        return preg_match('#\A(?:https://|http://|mailto:)#i', $url) === 1 ? $url : null;
    }

    /**
     * The SEO head tag block (seo-head spec §3): consumes the `seo` CONTEXT variable
     * (one source of truth) + the preview state. Preview emits ONLY noindex —
     * draft titles must never be canonicalized or socially scrapeable (spec §4).
     *
     * @param array<string,mixed> $context
     */
    public function seoHead(array $context): string
    {
        // previewContext, NOT isPreview(): the clean review surface has no
        // annotations but is still a draft render (surface split).
        if ($this->previewContext) {
            return '<meta name="robots" content="noindex, nofollow">';
        }
        $seo = $context['seo'] ?? null;
        if (!is_array($seo)) {
            return '';
        }
        $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        // Spec §3 URL safety: every URL attribute passes the SAME discipline the
        // safe_url template filter enforces — this class's own safeUrl(). A URL that
        // fails it is OMITTED, never emitted raw.
        $u = fn (mixed $v): ?string => $this->safeUrl($v);
        $lines = [];
        if (is_string($seo['description'] ?? null) && $seo['description'] !== '') {
            $lines[] = '<meta name="description" content="' . $e($seo['description']) . '">';
        }
        if (($canonical = $u($seo['canonical'] ?? null)) !== null) {
            $lines[] = '<link rel="canonical" href="' . $e($canonical) . '">';
        }
        foreach ((array) ($seo['alternates'] ?? []) as $alt) {
            $href = $u($alt['href'] ?? null);
            if ($href !== null && is_string($alt['locale'] ?? null)) {
                $lines[] = '<link rel="alternate" hreflang="' . $e($alt['locale']) . '" href="' . $e($href) . '">';
            }
        }
        if (($xDefault = $u($seo['x_default'] ?? null)) !== null) {
            $lines[] = '<link rel="alternate" hreflang="x-default" href="' . $e($xDefault) . '">';
        }
        $og = (array) ($seo['og'] ?? []);
        $lines[] = '<meta property="og:type" content="' . $e($og['type'] ?? 'article') . '">';
        $lines[] = '<meta property="og:title" content="' . $e($og['title'] ?? ($seo['title'] ?? '')) . '">';
        if (is_string($og['description'] ?? null) && $og['description'] !== '') {
            $lines[] = '<meta property="og:description" content="' . $e($og['description']) . '">';
        }
        if (($image = $u($og['image'] ?? null)) !== null) {
            $lines[] = '<meta property="og:image" content="' . $e($image) . '">';
        }
        if (($ogUrl = $u($og['url'] ?? null)) !== null) {
            $lines[] = '<meta property="og:url" content="' . $e($ogUrl) . '">';
        }
        // og:site_name from the SAME source the templates use — the render context's
        // site.name (needs_context makes it available; no parallel state).
        $siteName = is_array($context['site'] ?? null) ? (string) ($context['site']['name'] ?? '') : '';
        if ($siteName !== '') {
            $lines[] = '<meta property="og:site_name" content="' . $e($siteName) . '">';
        }
        if (is_string($seo['twitter_card'] ?? null)) {
            $lines[] = '<meta name="twitter:card" content="' . $e($seo['twitter_card']) . '">';
        }
        if (($seo['robots'] ?? 'index') !== 'index') {
            $lines[] = '<meta name="robots" content="' . $e($seo['robots']) . '">';
        }
        return implode("\n  ", $lines);
    }

    /**
     * Sanitized rich HTML for templates (sanitizer spec §4). Fail-closed, exactly:
     * no sanitizer bound OR the sanitizer throws → htmlspecialchars(ENT_QUOTES |
     * ENT_SUBSTITUTE, UTF-8). There is NO path returning unprocessed input.
     */
    public function safeHtml(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        if ($this->htmlSanitizer !== null) {
            try {
                return $this->markEditable($this->htmlSanitizer->sanitize($value));
            } catch (\Throwable) {
                // fall through to the escaped fallback
            }
        }
        return $this->markEditable(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * Opt-in edit-in-place marking for plain string/text fields
     * (editable-string-fields spec §1): annotated renders wrap the ESCAPED
     * value in a span region; live renders emit exactly the escaped value.
     * The field name is the TEMPLATE's claim — the admin's grant matrix is
     * the validator, so a bogus name yields a region that is never granted.
     * Non-string values render as ''.
     */
    public function editableText(mixed $value, string $field): string
    {
        // Markup input passes through un-re-escaped: it only originates from a
        // safe upstream filter (e.g. br_tokens, which escapes the author text
        // itself) — chaining `x|br_tokens|editable_text(...)` must not
        // double-escape. Plain strings keep the escape-here contract.
        $escaped = match (true) {
            $value instanceof \Twig\Markup => (string) $value,
            is_string($value) => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            default => '',
        };
        if (!$this->annotateBlocks || $this->blockFrames === []) {
            return $escaped;
        }
        $frame = $this->blockFrames[count($this->blockFrames) - 1];
        if (!is_string($frame['id'])) {
            return $escaped;
        }
        return '<span class="thallo-edit-region" data-thallo-edit-block="'
            . htmlspecialchars($frame['id'], ENT_QUOTES)
            . '" data-thallo-edit-field="'
            . htmlspecialchars($field, ENT_QUOTES)
            . '">' . $escaped . '</span>';
    }

    /**
     * Edit-in-place marking (spec §2): wraps safe_html OUTPUT with the editable
     * region ONLY when annotations are on AND the current block frame is prose
     * (editable_field non-null) AND the instance has a string id. Non-prose
     * blocks using safe_html produce no markers at all (review pin).
     */
    private function markEditable(string $safe): string
    {
        if (!$this->annotateBlocks || $this->blockFrames === []) {
            return $safe;
        }
        $frame = $this->blockFrames[count($this->blockFrames) - 1];
        if (!is_string($frame['id']) || $frame['editable_field'] === null) {
            return $safe;
        }
        return '<div class="thallo-edit-region" data-thallo-edit-block="'
            . htmlspecialchars($frame['id'], ENT_QUOTES)
            . '" data-thallo-edit-field="'
            . htmlspecialchars($frame['editable_field'], ENT_QUOTES)
            . '">' . $safe . '</div>';
    }

    /**
     * Render an ordered blocks list through blocks/{type}.twig (block-builder spec §6).
     * Context per block: {block, data, entry, index, site} — `entry` and `site` are
     * the CALLER's (needs_context), read-only ambient state (`site` so identity
     * blocks like `logo` can fall back to the site name). Missing templates: prod = HTML comment,
     * debug = visible placeholder; logged once per type per process. Malformed items
     * and path-unsafe type slugs are skipped with the same once-per-type logging — a
     * template never explodes over data. The block-type REGISTRY is never consulted
     * here: rendering is a pure template convention.
     *
     * Reference values inside `data` arrive EXPANDED (the target's published item —
     * fields under `.fields`, entry uuid under `.entry_uuid`; null when unpublished
     * or gated; raw uuid only at the expansion-depth cap). Link via
     * path(data.post.entry_uuid). Asset values stay raw blob uuids for media().
     *
     * @param array<string,mixed> $context
     */
    public function blocks(Environment $env, array $context, mixed $list): string
    {
        if (!is_array($list) || !array_is_list($list)) {
            return '';
        }
        // Depth cap (nesting amendment §A5): validation caps AUTHORED content at MAX;
        // this guards data written around the API. try/finally keeps the counter
        // honest through mid-render exceptions; resetBlockDepth() (reset family)
        // covers anything that escapes the render entirely.
        if ($this->blockDepth + 1 > self::MAX_BLOCK_DEPTH) {
            $this->logBlockMiss('(depth)', 'exceeds maximum block nesting depth');
            return $this->debug
                ? '<div style="border:1px dashed red;padding:.5rem">Blocks beyond maximum nesting depth</div>'
                : '';
        }
        $this->blockDepth++;
        try {
            $entry = $context['entry'] ?? null;
            $html = [];
            foreach ($list as $index => $item) {
                $type = is_array($item) && is_string($item['type'] ?? null) ? $item['type'] : null;
                if ($type === null || preg_match('/\A[a-z][a-z0-9_-]*\z/', $type) !== 1) {
                    $this->logBlockMiss($type ?? '(malformed)', 'malformed block instance');
                    continue;
                }
                $template = "blocks/{$type}.twig";
                if (!$env->getLoader()->exists($template)) {
                    $this->logBlockMiss($type, "no template at {$template}");
                    $html[] = $this->debug
                        ? '<div style="border:1px dashed red;padding:.5rem">Missing block template: '
                            . htmlspecialchars($template, ENT_QUOTES) . '</div>'
                        : '<!-- thallo: no template for block "' . htmlspecialchars($type, ENT_QUOTES) . '" -->';
                    continue;
                }
                $data = is_array($item['data'] ?? null) ? $item['data'] : [];
                // Settings (visual builder spec §1.2): every stored block carries them; the frame
                // and the block context expose them to the style helpers and templates.
                $settings = is_array($item['settings'] ?? null) ? $item['settings'] : [];
                $this->blockFrames[] = [
                    'id' => $item['id'] ?? null,
                    'type' => $type,
                    'settings' => $settings,
                    // Resolved ONLY when annotating: live renders never consult
                    // the resolver, and non-prose blocks get a null field.
                    'editable_field' => $this->annotateBlocks
                        ? $this->editableFields?->editableRichField($type)
                        : null,
                ];
                try {
                    $rendered = $env->render($template, [
                        'block' => [
                            'id' => $item['id'] ?? null,
                            'type' => $type,
                            'data' => $data,
                            'settings' => $settings,
                        ],
                        'data' => $data,
                        'entry' => $entry,
                        'site' => $context['site'] ?? [],
                        // Normalized request path (nav-v2 spec §3): passthrough
                        // like site — block templates get a FRESH context, so
                        // active-state detection needs the caller's value.
                        'current_path' => $context['current_path'] ?? null,
                        // Region identity for form_render()'s source key (form-block
                        // spec §5): set by regionBlocks(), null for page-body blocks.
                        'region_slug' => $context['region_slug'] ?? null,
                        'index' => $index,
                    ]);
                } finally {
                    array_pop($this->blockFrames);
                }
                // Preview-only annotation (visual-canvas spec §2): successfully
                // rendered instances with a string id only — missing-template
                // comments/placeholders carry nothing selectable.
                $html[] = $this->annotateBlocks && is_string($item['id'] ?? null)
                    ? '<div class="thallo-preview-block" data-thallo-block="'
                        . htmlspecialchars((string) $item['id'], ENT_QUOTES) . '">' . $rendered . '</div>'
                    : $rendered;
            }
            return implode('', $html);
        } finally {
            $this->blockDepth--;
        }
    }

    /**
     * Reset-before-every-render family (with resetTags/setAssetContext): an exception
     * that escapes a render entirely must not leak depth into the next response.
     */
    public function resetBlockDepth(): void
    {
        $this->blockDepth = 0;
    }

    /**
     * The nesting depth the next blocks() call starts from (visual builder spec §3.5): a
     * fragment renders a root at the depth the page render reaches it, so the depth cap holds
     * the same either way.
     */
    public function setBlockDepth(int $depth): void
    {
        $this->blockDepth = max(0, $depth);
    }

    /** Reset-family: an escaped exception must not leak frames into the next render. */
    public function resetBlockFrames(): void
    {
        $this->blockFrames = [];
    }

    /** Spec §4: the at-most-one LCP claim; reset at every render boundary. */
    public function resetPriorityImageClaim(): void
    {
        $this->priorityImageClaimed = false;
    }

    /**
     * The single per-render reset list (spec §4): the verbs EVERY render boundary shares —
     * including the asset context (font spec §3), so a mid-render exception can never leak
     * a preview base/dir onward. Boundaries therefore RESET FIRST, THEN setAssetContext().
     * Site-specific resets (tags, locale, appearance) stay at their call sites — they
     * genuinely differ per boundary and folding them would change behavior.
     */
    public function resetPerRenderState(): void
    {
        $this->resetBlockDepth();
        $this->resetBlockFrames();
        $this->resetPriorityImageClaim();
        $this->emittedBlockScripts = [];
        $this->setAssetContext(null, null);
        // Defaults-off here (not assignment-per-path like annotation) so render
        // paths unaware of the surface split — e.g. pack fragment renderers —
        // can never leak a previous preview's context into a live response.
        $this->previewContext = false;
    }

    /**
     * needs_context (spec §4): region-rendered blocks never claim (region_slug non-null in
     * the block context); the first body caller wins, everyone after gets false. Templates
     * call this ONLY after media_image() resolved non-null.
     *
     * @param array<string,mixed> $context
     */
    public function claimPriorityImage(array $context): bool
    {
        if (($context['region_slug'] ?? null) !== null || $this->priorityImageClaimed) {
            return false;
        }
        $this->priorityImageClaimed = true;
        return true;
    }

    /** Reset-family (see $annotateBlocks): the controller assigns per render. */
    public function setBlockAnnotations(bool $on): void
    {
        $this->annotateBlocks = $on;
    }

    private function logBlockMiss(string $type, string $reason): void
    {
        if (isset($this->loggedBlockMisses[$type])) {
            return;
        }
        $this->loggedBlockMisses[$type] = true;
        $this->logger?->warning("thallo-render: blocks(): {$reason}", ['type' => $type]);
    }

    /**
     * Facet counts for templates (preview spec §5): returns ITEMS to Twig; the result's
     * cache_tags go into the render-scoped collector so the controller can merge them
     * into the page's Cache-Tag. No reader bound (or any gate failing) → [] — a
     * template never explodes over facets.
     *
     * @return list<array{uuid: string, slug: ?string, count: int}>
     */
    public function facets(string $type, string $field, int $limit = 100): array
    {
        if ($this->facetReader === null) {
            return [];
        }
        $result = $this->facetReader->counts($type, $field, $this->locale, $limit);
        $this->collectTags($result['cache_tags']);
        return $result['items'];
    }

    /**
     * Published-entry listing for templates (the blog_posts block). Null reader →
     * [] (block renders nothing). Carries its own cache tags — including the broad
     * thallo:type:{slug} dependency — which are collected into the render's Cache-Tag
     * header just like facets().
     *
     * @param array{limit?: int, order?: string, category?: ?string} $opts
     * @return list<array<string,mixed>>
     */
    public function entries(string $type, array $opts = []): array
    {
        if ($this->entryReader === null) {
            return [];
        }
        $result = $this->entryReader->list($type, $opts, $this->locale);
        $this->collectTags($result['cache_tags']);
        return $result['items'];
    }

    /**
     * True only in the editor/canvas block-annotation render mode — NOT a session or
     * token check. Templates use it to show an empty-state placeholder in the editor
     * while rendering nothing on the public site.
     */
    public function isPreview(): bool
    {
        return $this->annotateBlocks;
    }

    /**
     * Preview-context flag (surface split): TRUE for ANY preview render — the
     * annotated design canvas AND the clean review surface. Distinct from
     * annotation on purpose: a review preview renders live-fidelity markup
     * (behaviors run, no carriers, no editor hints — is_preview() stays false),
     * but its DRAFT content must still never be canonicalized or socially
     * scrapeable, so the SEO discipline keys off THIS flag. Cleared by
     * resetPerRenderState(); set after reset by the preview-aware controller.
     */
    private bool $previewContext = false;

    public function setPreviewContext(bool $on): void
    {
        $this->previewContext = $on;
    }

    /** @param list<string> $tags */
    private function collectTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->collectedTags[$tag] = $tag;
        }
    }

    /** Reset the render-scoped collector — the controller calls this BEFORE every render. */
    public function resetTags(): void
    {
        $this->collectedTags = [];
    }

    /**
     * Render-scoped asset context (default-theme-font spec §3). (null, null) restores
     * constructor-backed live-theme behavior: '/theme-assets' base with ?t/&v busters and
     * the boot theme's assets dir. A themed preview passes ITS base AND ITS dir so URL
     * emission and existence checks can never disagree on which theme is being served.
     * Cleared inside resetPerRenderState() — boundaries RESET FIRST, THEN set.
     */
    public function setAssetContext(?string $base, ?string $assetsDir): void
    {
        $this->assetBase = $base;
        $this->assetContextDir = $assetsDir;
    }

    /** The directory asset()/font_faces_style() existence+mtime checks consult. */
    private function effectiveAssetsDir(): ?string
    {
        return $this->assetContextDir ?? $this->themeAssetsDir;
    }

    /** @return list<string> drained (and cleared) tags collected during the render */
    public function drainTags(): array
    {
        $tags = array_values($this->collectedTags);
        $this->collectedTags = [];
        return $tags;
    }

    /** @return list<array{label:string,url:string,entry:?string,children:list<mixed>}> */
    public function menu(string $slug): array
    {
        return $this->menus?->menu($slug, $this->locale) ?? [];
    }

    public function path(string $entryUuid): ?string
    {
        return $this->targets->resolve($entryUuid, $this->locale)['path'];
    }

    public function asset(string $rel): string
    {
        $bad = $rel === ''
            || str_starts_with($rel, '/')
            || str_contains($rel, '\\')
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $rel) === 1
            || in_array('..', explode('/', $rel), true);
        if ($bad) {
            throw new RuntimeError(sprintf(
                'asset(): "%s" is not a safe theme-relative path (no absolute URLs, "..", leading "/" or "\\").',
                $rel,
            ));
        }
        $url = ($this->assetBase ?? '/theme-assets') . '/' . $rel;
        // Theme cache-buster (theme-setting spec §3 P1): live base only — the
        // preview pipeline's setAssetContext override is already theme-pinned and
        // must not be rewritten. Browser caches don't see page-cache purges;
        // the ?t= makes a theme switch re-fetch every asset immediately.
        if ($this->assetBase === null && $this->themeSource !== null) {
            $url .= '?t=' . rawurlencode($this->themeSource->name());
            // Content fingerprint (theme-setting spec §3 P1): the `?t=` above only busts
            // on a theme SWITCH; append the file's mtime so an EDIT to a theme asset
            // re-fetches immediately instead of waiting out the 24h max-age. A missing
            // file gets no `&v=` (its 404 was never cacheable-stale to begin with).
            $dir = $this->effectiveAssetsDir();
            if ($dir !== null) {
                $mtime = @filemtime($dir . '/' . $rel);
                if ($mtime !== false) {
                    $url .= '&v=' . $mtime;
                }
            }
        }
        return $url;
    }

    /**
     * Preload + @font-face emission for a theme-owned webfont (default-theme-font spec §3).
     * ONE URL derivation feeds both sinks so they are byte-identical on the wire; every
     * dynamic value is escaped for its EXACT sink (the function is DB-template-callable):
     * the href is HTML-attribute-escaped, CSS strings are CSS-escaped (backslash-hex for
     * quotes, backslashes, control chars, and `<` so nothing can form `</style>`). A
     * missing roman emits nothing — a theme without the files (custom theme inheriting the
     * default layout) falls through to the system stack. Roman only is preloaded.
     */
    public function fontFacesStyle(string $family, string $romanRel, ?string $italicRel = null): Markup
    {
        $romanUrl = $this->assetUrlIfExists($romanRel);
        if ($romanUrl === null) {
            return new Markup('', 'UTF-8');
        }
        $italicUrl = $italicRel !== null ? $this->assetUrlIfExists($italicRel) : null;

        $css = '@font-face { font-family: "' . self::cssEscape($family) . '"; '
            . 'src: url("' . self::cssEscape($romanUrl) . '") format("woff2"); '
            . 'font-weight: 300 900; font-style: normal; font-display: swap; }';
        if ($italicUrl !== null) {
            $css .= "\n@font-face { font-family: \"" . self::cssEscape($family) . '"; '
                . 'src: url("' . self::cssEscape($italicUrl) . '") format("woff2"); '
                . 'font-weight: 300 900; font-style: italic; font-display: swap; }';
        }

        $html = '<link rel="preload" as="font" type="font/woff2" href="'
            . htmlspecialchars($romanUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" crossorigin>' . "\n<style>\n" . $css . "\n</style>";

        return new Markup($html, 'UTF-8');
    }

    /** asset() with existence gating against the effective (context ?? boot) dir. */
    private function assetUrlIfExists(string $rel): ?string
    {
        $url = $this->asset($rel); // path-safety exception behavior shared verbatim
        $dir = $this->effectiveAssetsDir();
        if ($dir === null || !is_file($dir . '/' . $rel)) {
            return null;
        }
        return $url;
    }

    /** CSS string escape: backslash-hex for quotes/backslash/control/`<` (spec §3). */
    private static function cssEscape(string $value): string
    {
        return preg_replace_callback(
            '/[\x00-\x1F\x7F"\'\\\\<>]/',
            static fn (array $m): string => sprintf('\\%x ', ord($m[0][0])),
            $value,
        ) ?? '';
    }
}
