<?php

declare(strict_types=1);

use Thallo\Render\Http\Controllers\RenderController;
use Thallo\Render\Http\Controllers\RuntimeAssetController;
use Thallo\Render\Http\Middleware\PreviewSessionMiddleware;
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
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);

// Token-scoped preview theme assets (preview-sessions spec §5): only the token's
// SIGNED theme is served; junk tokens and theme-less tokens 404. No page cache —
// preview assets are no-store like every other preview surface.
$router->get('/_preview-assets/{token}/{path}', [RenderController::class, 'previewAsset'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap'])
    ->where('path', '.+');

// Canvas bridge support (visual-canvas spec §3): token-free STATIC assets injected
// into preview HTML — cacheable, and OpenAPI-excluded via the Default tag like the
// other HTML-surface routes. Literal first segments win over the '*' catch-all.
$router->get('/_preview.css', [RenderController::class, 'previewCss'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);
$router->get('/_preview-bridge.js', [RenderController::class, 'previewBridgeJs'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);

// Site custom CSS (custom-css spec §3): DB-backed stylesheet, immutable-cached —
// the layout links it with ?v={version_uuid}, so every save changes the URL.
// Static route: wins over the '*' page catch-all by router bucketing.
$router->get('/custom.css', [RenderController::class, 'customCss'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);

// Theme runtime (theme-runtime spec §2.3): the package-owned behavior runtime,
// content-fingerprinted. `runtime.js` is the stable alias templates emit (302 to the
// current fingerprint, never cached); only the exact current fingerprint serves bytes
// (immutable). Static first segment wins over the '*' catch-all.
$router->get('/_thallo/runtime/{file}', [RuntimeAssetController::class, 'serve'])
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
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', PreviewSessionMiddleware::class, RenderPageCache::class]);
$router->get('/{path}', [RenderController::class, 'page'])
    ->where('path', '.+')
    ->middleware(['tenant_profile:public', 'tenant_bootstrap', PreviewSessionMiddleware::class, RenderPageCache::class]);
