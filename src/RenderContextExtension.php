<?php

declare(strict_types=1);

namespace Thallo\Render;

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
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

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
    public const MAX_BLOCK_DEPTH = 3;

    /** Render-scoped nesting depth (see resetBlockDepth). */
    private int $blockDepth = 0;

    /** Render-scoped priority-image claim (see claimPriorityImage/resetPriorityImageClaim). */
    private bool $priorityImageClaimed = false;

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
    ) {
        $this->locale = $defaultLocale;
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
            new TwigFunction('site_favicon', $this->siteFavicon(...)),
            new TwigFunction('custom_css', $this->customCss(...)),
            new TwigFunction('color_mode_enabled', $this->colorModeEnabled(...)),
            new TwigFunction('runtime_script', $this->runtimeScript(...)),
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
            // The fingerprinted storefront stylesheet for the theme <head> — null when
            // commerce is off or the seam is unbound, so the theme emits no <link> at all.
            new TwigFunction('shop_styles_url', $this->shopStylesUrl(...)),
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
    public function shopStylesUrl(): ?string
    {
        return $this->storefrontLinks?->stylesheetUrl();
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

        $css = ThemeColors::css($accent, $neutral);
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

    /** Style-block spec §4.3: namespaced, sanitized custom-CSS class hook. */
    public function styleHook(mixed $value): string
    {
        return self::sanitizeStyleHook(is_string($value) ? $value : '');
    }

    /**
     * Pure sanitizer for the class hook (pin 7). Keeps only tokens matching
     * ^[A-Za-z_-][A-Za-z0-9_-]*$, strips any existing thallo-style- prefix
     * (idempotent), then namespaces each under thallo-style-. Returns a
     * leading-space-joined string, or '' when nothing survives.
     */
    public static function sanitizeStyleHook(string $raw): string
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            if (str_starts_with($token, 'thallo-style-')) {
                $token = substr($token, strlen('thallo-style-'));
            }
            if (preg_match('/^[A-Za-z_-][A-Za-z0-9_-]*$/', $token) !== 1) {
                continue;
            }
            $out[] = 'thallo-style-' . $token;
        }
        return $out === [] ? '' : ' ' . implode(' ', $out);
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
            // No is_safe: sanitized output is autoescape-safe (a deliberate second
            // layer over the sanitizer, since the input is operator-derived).
            new TwigFilter('style_hook', $this->styleHook(...)),
        ];
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
        if ($this->isPreview()) {
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
        $escaped = is_string($value)
            ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : '';
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
                $this->blockFrames[] = [
                    'id' => $item['id'] ?? null,
                    // Resolved ONLY when annotating: live renders never consult
                    // the resolver, and non-prose blocks get a null field.
                    'editable_field' => $this->annotateBlocks
                        ? $this->editableFields?->editableRichField($type)
                        : null,
                ];
                try {
                    $rendered = $env->render($template, [
                        'block' => ['id' => $item['id'] ?? null, 'type' => $type, 'data' => $data],
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
        $this->setAssetContext(null, null);
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
