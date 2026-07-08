<?php

declare(strict_types=1);

namespace Thallo\Render;

use Thallo\Contracts\Content\BlockEditableFieldResolver;
use Thallo\Contracts\Content\RegionReader;
use Thallo\Contracts\Content\RichHtmlSanitizer;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Delivery\EntryListReader;
use Thallo\Contracts\Delivery\FacetCountsReader;
use Thallo\Contracts\Delivery\MediaUrlResolver;
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

    /** Render-scoped asset-base override (see setAssetBase). */
    private ?string $assetBase = null;

    /**
     * Nesting amendment §A2: mirrors the app-side BlockDepth::MAX (packs cannot
     * import app classes); an app-side test asserts the two agree.
     */
    public const MAX_BLOCK_DEPTH = 3;

    /** Render-scoped nesting depth (see resetBlockDepth). */
    private int $blockDepth = 0;

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
            // is_safe html: trusted, static, theme-owned resolver (mirrors icon()).
            new TwigFunction('color_mode_script', $this->colorModeScript(...), ['is_safe' => ['html']]),
            // is_safe html: generated purely from the closed accent/neutral enums.
            new TwigFunction('theme_colors_style', $this->themeColorsStyle(...), ['is_safe' => ['html']]),
            // No is_safe: returns an array whose members carry their own safety (P2a).
            new TwigFunction('theme_style_scope', $this->themeStyleScope(...)),
        ];
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
            $html = $this->blocks($env, $context, $list);
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
     * Reset-before-every-render family (with resetTags/setAssetBase): an exception
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
     * Per-render asset-base override (preview-sessions spec §5): themed previews emit
     * /_preview-assets/{token}/… so theme B's markup never loads theme A's assets.
     * Same reset discipline as the tag collector — the controller nulls it BEFORE
     * every render, so a mid-render exception cannot leak preview URLs onward.
     */
    public function setAssetBase(?string $base): void
    {
        $this->assetBase = $base;
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
        // preview pipeline's setAssetBase override is already theme-pinned and
        // must not be rewritten. Browser caches don't see page-cache purges;
        // the ?t= makes a theme switch re-fetch every asset immediately.
        if ($this->assetBase === null && $this->themeSource !== null) {
            $url .= '?t=' . rawurlencode($this->themeSource->name());
            // Content fingerprint (theme-setting spec §3 P1): the `?t=` above only busts
            // on a theme SWITCH; append the file's mtime so an EDIT to a theme asset
            // re-fetches immediately instead of waiting out the 24h max-age. A missing
            // file gets no `&v=` (its 404 was never cacheable-stale to begin with).
            if ($this->themeAssetsDir !== null) {
                $mtime = @filemtime($this->themeAssetsDir . '/' . $rel);
                if ($mtime !== false) {
                    $url .= '&v=' . $mtime;
                }
            }
        }
        return $url;
    }
}
