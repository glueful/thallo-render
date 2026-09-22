<?php

declare(strict_types=1);

use Thallo\Render\Http\Controllers\RenderController;
use Thallo\Render\Http\Controllers\RuntimeAssetController;
use Thallo\Render\Http\Controllers\ThemeScreenshotController;
use Thallo\Render\Themes\ThemeCard;
use Thallo\Render\Http\Middleware\PreviewSessionMiddleware;
use Thallo\Render\Http\Middleware\SiteSecurityHeaders;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * The rendered site surface (loads only when thallo.render is enabled). GET /{path} with
 * a slash-spanning constraint lives in the router's '*' bucket — tried after every
 * static route and literal-first-segment bucket, i.e. a TRUE lowest-priority catch-all
 * (V2 §2). The controller's reserved-path guard returns standard JSON 404s for /v1 etc.
 *
 * Deliberately NO rate_limit on page views: this is the whole-site surface, not an API.
 * The abuse posture is the render cache (RenderPageCache per-path 200s + the fixed
 * single-body 404/410 keys in RenderErrorCache — bogus paths can't fill the cache or
 * re-render templates).
 */
// Preview session exit (preview-sessions spec §1) — registered BEFORE the token
// route so the literal segment wins.
$router->get('/_preview/exit', [RenderController::class, 'exit'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);

// Preview-through-theme (preview spec §1): the signed token IS the authorization.
// Deliberately NO RenderPageCache middleware — the cache bypass is structural; a
// preview response can never enter or read the shared page cache. The static first
// segment wins over the '*'-bucket catch-all.
$router->get('/_preview/{token}', [RenderController::class, 'preview'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', SiteSecurityHeaders::class]);

// Token-scoped preview theme assets (preview-sessions spec §5): only the token's
// SIGNED theme is served; junk tokens and theme-less tokens 404. No page cache —
// preview assets are no-store like every other preview surface. Under /_thallo/ with every
// other PHP-served asset: these are `.css` and `.woff2` URLs, which a host's static-file rule
// answers 404 unless their prefix is one docs/production.md has it hand to PHP.
$router->get(RenderController::PREVIEW_ASSETS_PREFIX . '/{token}/{path}', [RenderController::class, 'previewAsset'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap'])
    ->where('path', '.+');

// Canvas bridge support (visual-canvas spec §3): token-free STATIC assets injected
// into preview HTML — cacheable, and OpenAPI-excluded via the Default tag like the
// other HTML-surface routes. Literal first segments win over the '*' catch-all.
// Under /_thallo/ with the runtime: every PHP-served asset shares the one prefix pair the
// web-server rule must proxy (docs/production.md) — a root-level *.css/*.js URL is eaten by
// static-file rules and 404s even on a host that proxied the documented prefixes.
$router->get(RenderController::LAYERS_CSS_PATH, [RenderController::class, 'layersCss'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);
$router->get(RenderController::PREVIEW_CSS_PATH, [RenderController::class, 'previewCss'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);
$router->get(RenderController::PREVIEW_BRIDGE_PATH, [RenderController::class, 'previewBridgeJs'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);

// Site custom CSS (custom-css spec §3): DB-backed stylesheet, immutable-cached —
// the layout links it with ?v={version_uuid}, so every save changes the URL.
// Under /_thallo/ with the other PHP-served assets: a web server's static-file rule
// that answers every .css from disk 404s a root-level path, and the documented
// nginx block already hands /_thallo/* to PHP.
// Static route: wins over the '*' page catch-all by router bucketing.
$router->get('/_thallo/custom.css', [RenderController::class, 'customCss'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);

// Theme runtime (theme-runtime spec §2.3): the package-owned behavior runtime,
// content-fingerprinted. `runtime.js` is the stable alias templates emit (302 to the
// current fingerprint, never cached); only the exact current fingerprint serves bytes
// (immutable). Static first segment wins over the '*' catch-all.
$router->get('/_thallo/runtime/{file}', [RuntimeAssetController::class, 'serve'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);

// A selectable theme's gallery screenshot, for the admin's theme cards: public because an <img>
// carries no token, and one fixed file per theme (the request names no path). Under /_thallo/
// like every PHP-served asset — a root-level *.jpg URL is a static-file rule's to 404.
$router->get(ThemeCard::SCREENSHOT_ROUTE . '/{theme}', [ThemeScreenshotController::class, 'serve'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);

// Live theme assets (theme-setting spec §3): served from the ACTIVE theme per
// request (the boot-time static mount is gone — a settings-driven theme switch
// applies without a restart). asset() emits ?t={theme} as the cache-buster.
$router->get('/theme-assets/{path}', [RenderController::class, 'themeAsset'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap'])
    ->where('path', '.+');

// Session detection runs BEFORE the page cache (preview-sessions spec §4): session
// state is not cache state, and verified sessions bypass the cache wholesale.
$router->get('/', [RenderController::class, 'home'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', SiteSecurityHeaders::class, PreviewSessionMiddleware::class, RenderPageCache::class]);
$router->get('/{path}', [RenderController::class, 'page'])
    ->where('path', '.+')
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', SiteSecurityHeaders::class, PreviewSessionMiddleware::class, RenderPageCache::class]);
