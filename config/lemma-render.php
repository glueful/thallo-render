<?php

declare(strict_types=1);

return [
    // NOTE: enable/disable is NOT configured here — the capability switchboard in the app's
    // config/lemma.php ('capabilities' => ['lemma.render' => false]) is the only gate.

    // Active theme name: an app-level themes/{name}/ directory, falling back to the
    // pack-embedded default theme. RESOLVED AT BOOT (v1): changing it requires an app
    // restart / extension-cache rebuild.
    'theme' => env('RENDER_THEME', 'default'),

    // Entry uuid rendered at `/` (through index.twig) — the DEPLOY DEFAULT: the
    // Settings › General homepage (lemma_settings row, editable in the admin)
    // overrides this while it resolves to published public content. Empty =
    // standalone index.twig. A set-but-unresolvable ENV value is a LOUD 500
    // config error (never a themed 404); a broken DB override logs + falls back.
    'homepage_entry' => env('RENDER_HOMEPAGE_ENTRY', ''),

    // site.name in the template context.
    'site_name' => env('RENDER_SITE_NAME', 'Lemma'),

    // First-PATH-SEGMENT prefixes the catch-all must never render ('v1' reserves /v1 and
    // /v1/... but NOT /v1abc). Reserved hits return the framework's standard JSON 404.
    // Admin SPA base URL for the preview bar's "Edit"/"Design" links (e.g.
    // https://admin.example.com). Empty = the links don't render.
    'admin_url' => env('RENDER_ADMIN_URL', ''),

    'reserved_prefixes' => ['v1', 'admin', 'extensions', 'theme-assets'],

    // Exact reserved paths ('sitemap.xml' does not reserve /sitemap-history).
    'reserved_exact' => ['sitemap.xml', 'robots.txt'],

    // Full-page render cache (spec sub-project 3). false = exactly the uncached
    // behavior (set in dev while theming).
    'cache_enabled' => env('RENDER_CACHE_ENABLED', true),

    // Safety-net TTL per cached page (seconds); surrogate tags do the real
    // invalidation. On non-tag cache drivers this TTL is the ONLY freshness bound.
    'cache_ttl' => (int) env('RENDER_CACHE_TTL', 3600),

    // Content types with rendered listing pages at /{type} (and term archives at
    // /{type}/{field}/{term}) — comma-separated slugs. EMPTY (the default) keeps the
    // whole listing/archive grammar dormant. Types must also be publicly deliverable.
    'listing_types' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('RENDER_LISTING_TYPES', '')),
    ))),

    // Items per rendered listing/archive page (path-based pagination: /{type}/page/2).
    'listing_per_page' => (int) env('RENDER_LISTING_PER_PAGE', 10),

    // DB-edited templates (spec 2026-07-03 §7): admin-authored overrides layered over
    // the filesystem theme. false = ops kill-switch — pure filesystem loading
    // (pre-feature behavior) and the template admin routes are not registered.
    'db_templates' => env('RENDER_DB_TEMPLATES', true),

    // Site custom CSS (custom-css spec §2): save-time size cap for the DB-backed
    // custom.css, in bytes. Encoding + size are the ONLY gates — CSS is never
    // syntax-validated (a broken rule loses in the browser; it cannot 500 the site).
    'custom_css' => [
        'max_bytes' => (int) env('LEMMA_CUSTOM_CSS_MAX_BYTES', 262144),
    ],
];
