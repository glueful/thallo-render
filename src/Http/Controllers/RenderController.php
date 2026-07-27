<?php

declare(strict_types=1);

namespace Thallo\Render\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response as ApiResponse;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Settings\AdminUrlProvider;
use Thallo\Contracts\Delivery\FacetCountsReader;
use Thallo\Contracts\Delivery\HomepageEntryProvider;
use Thallo\Contracts\Delivery\PreviewSession;
use Thallo\Contracts\Delivery\PreviewSessionVerifier;
use Thallo\Contracts\Delivery\PublicRouteResolver;
use Thallo\Contracts\Delivery\SeoHeadResolver;
use Thallo\Render\Contribution\RenderContributionRegistry;
use Thallo\Render\HomepageConfigError;
use Thallo\Render\Http\Middleware\PreviewSessionMiddleware;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\RenderErrorCache;
use Thallo\Render\ReservedPaths;
use Thallo\Render\Templates\DatabaseTemplateLoader;
use Thallo\Render\Templates\RenderTemplateLoader;
use Thallo\Render\Templates\TemplateLinter;
use Thallo\Render\Templates\TemplateRepository;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;
use Thallo\Tenancy\Cache\TenantCacheSegment;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

use function config;

/**
 * The render pipeline: reserved-path guard → PublicRouteResolver → template hierarchy →
 * HTML. Raw Symfony responses (never the JSON envelope) EXCEPT reserved paths, which get
 * the framework's standard JSON 404 (byte-compatible with a render-less install). Render
 * exceptions try error.twig once, then a plain-text 500 — never a render loop.
 */
final class RenderController
{
    private ?Environment $twig = null;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PublicRouteResolver $resolver,
        private readonly TwigFactory $twigFactory,
        private readonly RenderContextExtension $extension,
        private readonly ReservedPaths $reserved,
        private readonly RenderErrorCache $errors,
        private readonly LoggerInterface $logger,
        private readonly ?FacetCountsReader $facetReader = null,
        private readonly ?PreviewSessionVerifier $sessionVerifier = null,
        private readonly ?TemplateRepository $templates = null,
        private readonly ?TemplateLinter $templateLinter = null,
        private readonly ?ThemeLocator $themes = null,
        private readonly ?HomepageEntryProvider $homepage = null,
        /** Preview-bar status/live-path lookup; null = bar shows no status. */
        private readonly ?EntryTargetResolver $targets = null,
        /** Admin base URL (DB setting first); null = config-only fallback. */
        private readonly ?AdminUrlProvider $adminUrlProvider = null,
        private readonly ?TenantCacheSegment $tenantCache = null,
        /**
         * Fix C (Commerce-Slice-2 review): frozen contributed template dirs for the
         * PREVIEW-SESSION ThemeLocator {@see themedEnv()} builds — mirrors
         * RenderServiceProvider::makeThemeLocator()'s boot-theme identical wiring. Null only
         * in a test/minimal-wiring construction; a real boot always binds this (the registry
         * is bound shared and unconditionally — see RenderContributionRegistry's own
         * docblock), and a null value degrades to the pre-Fix-C behavior (no contributed
         * dirs in a themed preview) rather than throwing.
         */
        private readonly ?RenderContributionRegistry $contributions = null,
        /** Composed SEO head data (seo-head spec §3); null = no head tags. */
        private readonly ?SeoHeadResolver $seoHeadResolver = null,
    ) {
    }

    /** @return array<string,mixed>|null */
    private function seoHead(string $entryUuid, string $locale): ?array
    {
        // Soft-bound (seo-head spec §3): absent wiring degrades to no head tags.
        return $this->seoHeadResolver?->headFor($entryUuid, $locale);
    }

    /**
     * Preview-only block annotation intent (visual-canvas spec §2): ASSIGNED at
     * the top of every rendering entry point (home/page: session-present;
     * preview(): always true) and applied to the extension in render()'s reset
     * block — assignment-not-set means the shared singleton never leaks
     * annotation across requests.
     */
    private bool $annotateBlocks = false;

    /**
     * The active preview session (theme-color-config spec §6): every entry point
     * ASSIGNS it (from the resolved session, or null for live renders) so render()'s
     * reset block can apply/clear the request-local appearance override without the
     * shared singleton leaking a previous request's preview skin.
     */
    private ?PreviewSession $appearanceSession = null;

    /** Normalized request path for the render context (nav-v2 spec §3). */
    private string $currentPath = '/';

    public function home(Request $request): Response
    {
        $this->currentPath = RenderPageCache::normalizePath($request->getPathInfo());
        $session = $this->session($request);
        $this->annotateBlocks = $session !== null;
        $this->appearanceSession = $session;
        // Source-aware provider (homepage-setting spec §0): the DB site setting
        // wins while resolvable; otherwise the provider already fell back to
        // env — whatever arrives here keeps deploy-config semantics (an
        // unresolvable value is the LOUD 500 below).
        $homepageEntry = $this->homepage !== null
            ? $this->homepage->homepageEntry()
            : (string) config($this->context, 'render.homepage_entry', '');
        $locale = (string) config($this->context, 'i18n.default_locale', 'en');
        $entry = null;
        $typeSlug = '';

        $extra = $this->sessionExtra($session);
        if ($homepageEntry !== '') {
            // Locale stays null (working-copy-overlay P1 note): today's call
            // passes none, and the controller's $locale above is only the
            // no-entry template fallback — forwarding the config default would
            // change resolution for entries routed in a non-default locale.
            $result = $this->resolver->resolveEntry($homepageEntry, null, $session);
            if ($result['kind'] !== 'content') {
                return $this->homepageConfigFailure($homepageEntry);
            }
            $entry = $result['content'];
            $locale = (string) $result['locale'];
            $typeSlug = (string) ($result['type'] ?? '');
            $extra['presentation'] = $this->presentationContext(
                $typeSlug !== '' ? $typeSlug : null,
                $result['presentation'] ?? null,
            );
            // The entry-backed homepage renders index.twig directly (never through
            // renderEntry), so it threads its own seo context — headFor() gives the
            // homepage shape: canonical '/', og:type website (seo-head spec §2).
            $extra['seo'] = $this->seoHead($homepageEntry, $locale);
        }

        // Homepage ALWAYS renders index.twig (spec §4) — the entry, when configured,
        // arrives as context; routed pages use the entry hierarchy instead.
        [$env, $assetBase] = $this->themedEnv($session);
        $response = $this->render('index.twig', $locale, $entry, 200, $extra, $env, $assetBase);
        if ($entry !== null) {
            $this->tagResponse($response, $entry, $typeSlug);
        }
        return $session !== null ? $this->sessionChrome($response) : $response;
    }

    public function page(Request $request, string $path): Response
    {
        $this->currentPath = RenderPageCache::normalizePath($request->getPathInfo());
        if ($this->reserved->isReserved($path)) {
            // Byte-compatible with the router's own 404 (shape + content type); API
            // clients under /v1 etc. never receive themed HTML.
            return ApiResponse::error('Not Found', 404);
        }

        $session = $this->session($request);
        $this->annotateBlocks = $session !== null;
        $this->appearanceSession = $session;
        $extra = $this->sessionExtra($session);
        [$env, $assetBase] = $this->themedEnv($session);
        $result = $this->resolver->resolvePath('/' . ltrim($path, '/'), $session);

        $response = match ($result['kind']) {
            'redirect' => new Response('', $result['redirect']['status'], [
                'Location' => $result['redirect']['location'],
            ]),
            // In-session 404/410 render FRESH with the chrome (preview-sessions spec
            // §3): session surfaces never read or fill the SHARED fixed bodies.
            'gone' => $session !== null
                ? $this->render('error.twig', $this->defaultLocale(), null, 410, $extra, $env, $assetBase)
                : $this->errors->themed410(
                    fn (): Response => $this->render('error.twig', $this->defaultLocale(), null, 410),
                ),
            'content' => $this->renderEntry($result, $extra, $env, $assetBase),
            'listing', 'archive' => $this->renderCollection(
                $result,
                '/' . ltrim($path, '/'),
                $extra,
                $env,
                $assetBase,
            ),
            'terms' => $this->renderTerms($result, $extra, $env, $assetBase),
            default => $session !== null
                ? $this->render('404.twig', $this->defaultLocale(), null, 404, $extra, $env, $assetBase)
                : $this->errors->themed404(
                    fn (): Response => $this->render('404.twig', $this->defaultLocale(), null, 404),
                ),
        };

        return $session !== null ? $this->sessionChrome($response) : $response;
    }

    /** The verified preview session from the detection middleware, if any. */
    private function session(Request $request): ?PreviewSession
    {
        $session = $request->attributes->get(PreviewSessionMiddleware::ATTRIBUTE);
        return $session instanceof PreviewSession ? $session : null;
    }

    /** @return array<string,mixed> banner context for in-session renders */
    private function sessionExtra(?PreviewSession $session): array
    {
        return $session === null
            ? []
            : ['preview' => true, 'preview_exit' => '/_preview/exit'];
    }

    /** Session/preview chrome (preview-sessions spec §3): no-store, noindex, no tags. */
    private function sessionChrome(Response $response): Response
    {
        $response->headers->remove('Cache-Tag');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');
        // Preview-session render, entry point TWO (visual-canvas spec §2 P1):
        // cookie-backed session pages get the bridge like the direct token route.
        return $this->withPreviewBridge($response);
    }

    /**
     * Request-local Twig for a themed session (preview-sessions spec §5) — NEVER
     * assigned to the memoized boot environment. Vanished/broken themes fall back to
     * the boot theme (the content exists; a themed-preview 404 would be wrong) and log.
     *
     * @return array{0: ?Environment, 1: ?string} [env, assetBase] — [null, null] = boot
     */
    private function themedEnv(?PreviewSession $session): array
    {
        if ($session === null || $session->theme === null) {
            return [null, null];
        }
        $base = $this->context->getBasePath();
        try {
            // Fix C (Commerce-Slice-2 review): pass the SAME frozen contributed template
            // dirs makeThemeLocator() passes for the boot theme — without this, a
            // pack-contributed template (e.g. a shop block template) vanishes from a
            // non-default-theme preview even though it renders fine on a live page.
            $locator = new ThemeLocator(
                $session->theme,
                $base . '/themes',
                null,
                $this->contributions?->frozenTemplatePaths() ?? [],
            );
            // DB overrides for the LOCATOR-RESOLVED theme (spec §3): a vanished theme
            // that fell back to `default` must not carry the vanished theme's overrides.
            $db = $this->templates !== null && $this->templateLinter !== null
                ? new DatabaseTemplateLoader(
                    $this->templates,
                    $this->templateLinter,
                    $locator->activePaths()['name'],
                    $this->tenantCache,
                    $this->context,
                )
                : null;
            $factory = new TwigFactory(
                $locator,
                $this->extension,
                $base . '/storage/cache/twig',
                $db,
            );
            return [$factory->environment(), '/_preview-assets/' . $session->token];
        } catch (\Throwable $e) {
            $this->logger->warning('thallo-render: preview theme unavailable, boot theme used', [
                'theme' => $session->theme,
                'error' => $e->getMessage(),
            ]);
            return [null, null];
        }
    }

    /**
     * Preview-through-theme (preview spec §2–§3): a content render with different
     * headers and context — kind 'content' + preview flag, never a separate kind. The
     * fixed-key RenderErrorCache is NOT consulted for failures (a preview 404 renders
     * fresh; it must never serve or fill the shared body). Responses are no-store +
     * noindex and carry no Cache-Tag.
     */
    #[\Glueful\Routing\Attributes\ApiOperation(
        summary: 'Rendered draft preview (HTML, not an API endpoint)',
        // Tagged Default explicitly: the OpenAPI deny-list drops the render pack's
        // HTML routes by that tag, and the generator would otherwise path-derive an
        // unexcludable "' Preview'" tag from the /_preview segment.
        tags: ['Default'],
    )]
    public function preview(Request $request, string $token): Response
    {
        $this->currentPath = RenderPageCache::normalizePath($request->getPathInfo());
        // Preview-session render, entry point ONE (visual-canvas spec §2 P1): this
        // route does NOT pass PreviewSessionMiddleware — the canvas iframe's first
        // load lands here, so annotation keys off controller knowledge, not the
        // middleware attribute.
        $this->annotateBlocks = true;
        // Verified up front: the session drives BOTH the cookie and the per-preview
        // theme (spec §5) — a themed token renders through a request-local environment.
        $session = $this->sessionVerifier?->verify($token);
        $this->appearanceSession = $session;
        [$env, $assetBase] = $this->themedEnv($session);
        $result = $this->resolver->resolvePreview($token);

        if ($result['kind'] !== 'content') {
            $response = $this->render(
                '404.twig',
                $this->defaultLocale(),
                null,
                404,
                ['preview' => true],
                $env,
                $assetBase,
            );
        } else {
            $entry = $result['content'];
            $locale = (string) $result['locale'];
            $typeSlug = (string) ($result['type'] ?? '');
            $candidate = $typeSlug !== '' ? "entry/{$typeSlug}.twig" : '';
            $template = $candidate !== '' && ($env ?? $this->twig())->getLoader()->exists($candidate)
                ? $candidate
                : 'entry.twig';
            $response = $this->render($template, $locale, $entry, 200, [
                'preview' => true,
                'preview_bar' => $session !== null
                    ? $this->previewBar($session->entry, $typeSlug, $locale)
                    : null,
                'presentation' => $this->presentationContext(
                    $typeSlug !== '' ? $typeSlug : null,
                    $result['presentation'] ?? null,
                ),
            ], $env, $assetBase);
        }

        $response->headers->remove('Cache-Tag'); // no-store pages carry no surrogate tags
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        // Start the preview SESSION (preview-sessions spec §1) — only on a VERIFIED
        // token: the cookie is the token itself, dies with its TTL, Secure iff HTTPS.
        if ($session !== null && $result['kind'] === 'content') {
            $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie(
                'thallo_preview',
                $token,
                $session->expiresAt,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                \Symfony\Component\HttpFoundation\Cookie::SAMESITE_LAX,
            ));
        }
        return $this->withPreviewBridge($response);
    }

    /**
     * Preview support stylesheet (visual-canvas spec §3): the layout-inert
     * annotation wrapper rule + canvas ring styles. Token-free static content.
     */
    #[\Glueful\Routing\Attributes\ApiOperation(
        summary: 'Preview support stylesheet (not an API endpoint)',
        tags: ['Default'],
    )]
    public function previewCss(): Response
    {
        return $this->previewSupportAsset('preview.css', 'text/css; charset=UTF-8');
    }

    /**
     * The canvas bridge script (visual-canvas spec §3): silent until a
     * nonce-carrying canvas-hello; token-free static content.
     */
    #[\Glueful\Routing\Attributes\ApiOperation(
        summary: 'Preview canvas bridge script (not an API endpoint)',
        tags: ['Default'],
    )]
    public function previewBridgeJs(): Response
    {
        return $this->previewSupportAsset('preview-bridge.js', 'application/javascript; charset=UTF-8');
    }

    /**
     * The site custom stylesheet (custom-css spec §3): the ACTIVE theme's
     * DB-backed custom.css row — DB-only, no filesystem fallback. The layout
     * links it as /custom.css?v={version_uuid}, so the immutable year-long
     * cache is safe: every save changes the URL. Absent or empty → plain 404
     * (the layout emits no link in that state; a stale reference downgrades
     * gracefully to unstyled).
     */
    #[\Glueful\Routing\Attributes\ApiOperation(
        summary: 'Site custom stylesheet (not an API endpoint)',
        tags: ['Default'],
    )]
    public function customCss(): Response
    {
        $row = $this->templates?->findCurrentSource(
            $this->themes?->activePaths()['name'] ?? 'default',
            'custom.css',
        );
        if ($row === null || trim((string) $row['source']) === '') {
            return new Response('', 404, ['Content-Type' => 'text/css; charset=UTF-8']);
        }
        return new Response((string) $row['source'], 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function previewSupportAsset(string $file, string $contentType): Response
    {
        $path = dirname(__DIR__, 3) . '/assets/preview/' . $file;
        $body = is_file($path) ? (string) file_get_contents($path) : '';
        return new Response($body, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Inject the canvas bridge into preview HTML (visual-canvas spec §3): applied
     * to BOTH preview response paths — the direct token entrypoint and cookie
     * sessions. Non-HTML responses (redirects, assets) pass through untouched;
     * missing </body> appends at end-of-document — NEVER fails the render.
     */
    private function withPreviewBridge(Response $response): Response
    {
        $type = (string) $response->headers->get('Content-Type', '');
        if (!str_contains($type, 'text/html')) {
            return $response;
        }
        // Version by mtime: the assets are served with max-age=86400, so without
        // a cache-busting query every bridge/CSS change ships a DAY late to any
        // browser that already previewed. Query strings don't affect routing.
        $assetDir = dirname(__DIR__, 3) . '/assets/preview/';
        $cssV = (int) @filemtime($assetDir . 'preview.css');
        $jsV = (int) @filemtime($assetDir . 'preview-bridge.js');
        $inject = '<link rel="stylesheet" href="/_preview.css?v=' . $cssV . '">'
            . '<script src="/_preview-bridge.js?v=' . $jsV . '" defer></script>';
        $html = (string) $response->getContent();
        $response->setContent(
            str_contains($html, '</body>')
                ? (string) preg_replace('#</body>#', $inject . '</body>', $html, 1)
                : $html . $inject
        );
        return $response;
    }

    /** Ends the preview session (preview-sessions spec §1): clear the cookie, go home. */
    #[\Glueful\Routing\Attributes\ApiOperation(
        summary: 'End the preview session (HTML redirect, not an API endpoint)',
        // Tagged Default explicitly (like preview/previewAsset): the OpenAPI deny-list
        // drops the render pack's HTML routes by that tag; the generator would
        // otherwise path-derive an unexcludable tag from the /_preview segment.
        tags: ['Default'],
    )]
    /**
     * The preview bar's context (admin-bar feature): REAL publish status via
     * EntryTargetResolver plus deep links back into the admin's editor and
     * design views. Everything degrades: no session -> null (plain banner);
     * no admin_url config -> no edit links; no targets binding -> no status.
     *
     * @return array{status: ?string, live_path: ?string, editor_url: ?string,
     *   design_url: ?string}|null
     */
    private function previewBar(string $entryUuid, string $typeSlug, string $locale): array
    {
        $target = $this->targets?->resolve($entryUuid, $locale);
        $adminUrl = rtrim(
            $this->adminUrlProvider?->adminUrl()
                ?? (string) config($this->context, 'render.admin_url', ''),
            '/',
        );
        $canLink = $adminUrl !== '' && $typeSlug !== '';
        return [
            'status' => $target !== null ? (string) $target['status'] : null,
            'live_path' => $target !== null && is_string($target['path'] ?? null) ? $target['path'] : null,
            'editor_url' => $canLink
                ? "{$adminUrl}/content/{$typeSlug}/{$entryUuid}?locale={$locale}"
                : null,
            'design_url' => $canLink
                ? "{$adminUrl}/content/{$typeSlug}/{$entryUuid}/design/{$locale}"
                : null,
        ];
    }

    public function exit(): Response
    {
        $response = new Response('', 302, ['Location' => '/']);
        $response->headers->clearCookie('thallo_preview', '/');
        return $response;
    }

    /**
     * Token-scoped preview assets (preview-sessions spec §5): the token's SIGNED theme
     * is the only theme served; asset() path rules apply; no-store. Tagged Default so
     * the OpenAPI deny-list drops it like the other HTML-surface routes.
     */
    #[\Glueful\Routing\Attributes\ApiOperation(
        summary: 'Preview theme assets (not an API endpoint)',
        tags: ['Default'],
    )]
    /**
     * Live theme assets (theme-setting spec §3): resolved from the ACTIVE
     * theme's assets dir PER REQUEST — replaces the boot-time static mount so
     * a settings-driven theme switch applies without a restart. Explicit
     * extension→MIME map (content-sniffing answers text/plain for CSS/JS —
     * the 1.65.3 serveFrontend lesson). asset() emits ?t={theme}, so the
     * day-long cache never crosses a theme switch.
     */
    #[\Glueful\Routing\Attributes\ApiOperation(
        summary: 'Theme asset (not an API endpoint)',
        tags: ['Default'],
    )]
    public function themeAsset(Request $request, string $path): Response
    {
        $bad = $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1
            || in_array('..', explode('/', $path), true);
        if ($bad) {
            return ApiResponse::error('Not Found', 404);
        }
        $assets = $this->themes?->activePaths()['assets'] ?? null;
        $file = $assets !== null ? $assets . '/' . $path : null;
        if ($file === null || !is_file($file)) {
            return ApiResponse::error('Not Found', 404);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::ASSET_MIME[$ext] ?? 'application/octet-stream';
        return new Response((string) file_get_contents($file), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /** Extension→MIME for theme assets (explicit — never content-sniffed). */
    private const ASSET_MIME = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'mjs' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'map' => 'application/json; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
    ];

    public function previewAsset(Request $request, string $token, string $path): Response
    {
        $session = $this->sessionVerifier?->verify($token);
        if ($session === null || $session->theme === null) {
            return ApiResponse::error('Not Found', 404);
        }
        $bad = $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1
            || in_array('..', explode('/', $path), true);
        if ($bad) {
            return ApiResponse::error('Not Found', 404);
        }
        try {
            $assets = (new ThemeLocator($session->theme, $this->context->getBasePath() . '/themes'))
                ->activePaths()['assets'];
        } catch (\Throwable) {
            return ApiResponse::error('Not Found', 404);
        }
        $file = $assets . '/' . $path;
        if (!is_file($file)) {
            return ApiResponse::error('Not Found', 404);
        }
        $response = new BinaryFileResponse($file);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * @param array{locale: ?string, type: ?string, content: ?array} $result may also
     *        carry `cache_tags` (expansion-target surrogate tags) — merged into the
     *        response header, never into template context
     * @param array<string,mixed> $extra session banner context, when in a session
     * @param Environment|null $env request-local themed environment (themed sessions)
     */
    private function renderEntry(
        array $result,
        array $extra = [],
        ?Environment $env = null,
        ?string $assetBase = null,
    ): Response {
        $entry = $result['content'];
        $locale = (string) $result['locale'];
        // Template hierarchy: entry/{type-slug}.twig → entry.twig (the resolver's `type`
        // field carries the content-type slug for exactly this selection).
        $typeSlug = (string) ($result['type'] ?? '');
        $candidate = $typeSlug !== '' ? "entry/{$typeSlug}.twig" : '';

        $template = $candidate !== '' && ($env ?? $this->twig())->getLoader()->exists($candidate)
            ? $candidate
            : 'entry.twig';
        $extra['presentation'] = $this->presentationContext(
            $typeSlug !== '' ? $typeSlug : null,
            $result['presentation'] ?? null,
        );
        // seo-head spec §3: composed head data for the SAME entry identity the
        // cache tags below carry (tagResponse's uuid derivation) — entry renders
        // are the ONLY context that gains the `seo` key.
        $uuid = is_string($entry['uuid'] ?? null) ? $entry['uuid'] : '';
        $extra['seo'] = $uuid !== '' ? $this->seoHead($uuid, $locale) : null;
        // In-session chrome gets the admin bar for the entry BEING VIEWED —
        // browsing another page in-session offers "edit what you see".
        if (($extra['preview'] ?? false) === true && is_string($entry['entry_uuid'] ?? null)) {
            $extra['preview_bar'] = $this->previewBar((string) $entry['entry_uuid'], $typeSlug, $locale);
        }
        $response = $this->render($template, $locale, $entry, 200, $extra, $env, $assetBase);
        $this->tagResponse($response, $entry ?? [], $typeSlug);
        $this->mergeCacheTags($response, array_values(array_map('strval', (array) ($result['cache_tags'] ?? []))));
        return $response;
    }

    /**
     * Listing/archive pages (listing spec §4). Template family follows the kind
     * (listing/{type}.twig → listing.twig; archive/{type}.twig → archive.twig); the
     * context ships ready pagination paths so themes never build page URLs; the
     * Cache-Tag ALWAYS carries the broad thallo:type:{type} — page contents change when
     * one new entry publishes, so per-item tags alone cannot keep cached pages fresh.
     *
     * @param array<string,mixed> $result
     * @param array<string,mixed> $sessionExtra session banner context, when in a session
     * @param Environment|null $env request-local themed environment (themed sessions)
     */
    private function renderCollection(
        array $result,
        string $path,
        array $sessionExtra = [],
        ?Environment $env = null,
        ?string $assetBase = null,
    ): Response {
        $family = $result['kind'] === 'archive' ? 'archive' : 'listing';
        $typeSlug = (string) $result['type'];
        $locale = (string) $result['locale'];
        /** @var array<string,mixed> $listing */
        $listing = $result['listing'];

        $candidate = "{$family}/{$typeSlug}.twig";
        $template = ($env ?? $this->twig())->getLoader()->exists($candidate) ? $candidate : "{$family}.twig";

        $page = (int) $listing['page'];
        $totalPages = (int) $listing['total_pages'];
        // The base path strips a trailing /page/{n}; page 2's prev is the BARE base
        // (canonical — /page/1 301s).
        $base = $page > 1 ? (string) preg_replace('#/page/\d+$#', '', $path) : $path;
        $pagination = [
            'page' => $page,
            'per_page' => (int) $listing['per_page'],
            'total' => (int) $listing['total'],
            'total_pages' => $totalPages,
            'prev_path' => $page <= 1 ? null : ($page === 2 ? $base : $base . '/page/' . ($page - 1)),
            'next_path' => $page < $totalPages ? $base . '/page/' . ($page + 1) : null,
        ];

        $extra = $sessionExtra + [
            'items' => $listing['items'],
            'pagination' => $pagination,
            'type' => $typeSlug,
        ];
        if ($result['kind'] === 'archive') {
            $extra['term'] = $result['term'];
            $extra['field'] = $result['field'];
        }

        $response = $this->render($template, $locale, null, 200, $extra, $env, $assetBase);
        $this->tagCollection($response, $result);
        $this->mergeCacheTags($response, array_values(array_map('strval', (array) ($result['cache_tags'] ?? []))));
        return $response;
    }

    /**
     * Surrogate tags for a collection page: per-item entry tags + the BROAD type tag
     * (the correctness mechanism — see renderCollection); archives add the term's entry
     * tag and its type's tag so term edits and term-type events purge too.
     *
     * @param array<string,mixed> $result
     */
    private function tagCollection(Response $response, array $result): void
    {
        $typeSlug = (string) $result['type'];
        $tags = [];
        foreach ((array) ($result['listing']['items'] ?? []) as $item) {
            $uuid = is_string($item['uuid'] ?? null) ? $item['uuid'] : '';
            if ($uuid !== '') {
                $tags[] = 'thallo:entry:' . $uuid;
            }
        }
        $termUuid = is_string($result['term']['uuid'] ?? null) ? $result['term']['uuid'] : '';
        if ($termUuid !== '') {
            $tags[] = 'thallo:entry:' . $termUuid;
        }
        $tags[] = 'thallo:type:' . $typeSlug;
        $termType = is_string($result['term_type'] ?? null) ? $result['term_type'] : '';
        if ($termType !== '' && $termType !== $typeSlug) {
            $tags[] = 'thallo:type:' . $termType;
        }
        $this->mergeCacheTags($response, array_values(array_unique($tags)));
    }

    /**
     * Stamp the surrogate Cache-Tag header (same strings the delivery API emits and
     * InvalidateCacheTagsListener invalidates) so the page cache and the CDN can both
     * purge this page on entry/type events.
     *
     * @param array<string,mixed> $entry
     */
    private function tagResponse(Response $response, array $entry, string $typeSlug): void
    {
        $uuid = is_string($entry['uuid'] ?? null) ? $entry['uuid'] : '';
        if ($uuid === '' || $typeSlug === '') {
            return;
        }
        $this->mergeCacheTags($response, ["thallo:entry:{$uuid}", "thallo:type:{$typeSlug}"]);
    }

    /**
     * @param array<string,mixed>|null $entry
     * @param array<string,mixed> $extra additional template context (listing/archive pages)
     * @param Environment|null $twig request-local themed environment (preview sessions);
     *                               null = the memoized boot-theme environment
     * @param string|null $assetBase per-render asset() base (/_preview-assets/{token});
     *                               null = /theme-assets
     */
    /**
     * The composed presentation context (modern-default-theme spec §5a):
     * page override -> theme.json per-type setting -> theme.json default ->
     * built-ins (show_title: true, layout: centered). Templates consume ONLY
     * this variable — _presentation itself never reaches template context
     * (the shaper's schema allowlist already stripped it from entry.fields).
     * v1 note: settings come from the boot-active theme; themed preview
     * sessions inherit them.
     *
     * @param array<string,mixed>|null $override the raw per-page _presentation
     * @return array{show_title: bool, layout: string, header: string, footer: string}
     */
    private function presentationContext(?string $typeSlug, ?array $override): array
    {
        $settings = $this->themes?->settings() ?? [];
        $type = $typeSlug !== null && is_array($settings['types'][$typeSlug] ?? null)
            ? $settings['types'][$typeSlug]
            : [];
        $layout = $override['layout'] ?? $type['layout'] ?? $settings['layout'] ?? 'centered';
        // Chrome suppression (global-regions spec §7): anything but the exact
        // 'hidden' composes to 'default' — future variant values degrade safely.
        $header = $override['header'] ?? $type['header'] ?? $settings['header'] ?? 'default';
        $footer = $override['footer'] ?? $type['footer'] ?? $settings['footer'] ?? 'default';
        return [
            'show_title' => (bool) ($override['show_title'] ?? $type['show_title'] ?? $settings['show_title'] ?? true),
            'layout' => $layout === 'full' ? 'full' : 'centered',
            'header' => $header === 'hidden' ? 'hidden' : 'default',
            'footer' => $footer === 'hidden' ? 'hidden' : 'default',
        ];
    }

    private function render(
        string $template,
        string $locale,
        ?array $entry,
        int $status,
        array $extra = [],
        ?Environment $twig = null,
        ?string $assetBase = null,
    ): Response {
        // Reset the render-scoped state BEFORE every render (preview + DB-template
        // specs): the extension instance — and the boot environment's loader — are
        // process-shared; reset-before-render is what stops a failed render's
        // collected tags, a themed preview's asset base, or a stale template override
        // map leaking into the next response.
        $env = $twig ?? $this->twig();
        $loader = $env->getLoader();
        if ($loader instanceof RenderTemplateLoader) {
            $loader->resetForRender(); // reload the override map once per render (spec §3)
        }
        $this->extension->resetTags();
        $this->extension->setAssetBase($assetBase);
        $this->extension->resetPerRenderState();
        // theme-color-config spec §6: a verified preview session's signed appearance
        // overrides the saved/default pair for THIS render only; null clears it so a
        // normal render falls back to the source. Reset-before-render discipline.
        $this->extension->setThemeAppearanceOverride(
            $this->appearanceSession?->accent,
            $this->appearanceSession?->neutral,
        );
        // Controller-scoped intent, applied per render: every entry point ASSIGNS
        // $annotateBlocks (true only for preview renders), so the shared singleton
        // can never leak annotation into a live response.
        $this->extension->setBlockAnnotations($this->annotateBlocks);
        $this->extension->setLocale($locale);
        $context = [
            'site' => [
                'name' => (string) config($this->context, 'render.site_name', 'Thallo'),
                'locale' => $locale,
                'locales' => [],
            ],
            // Normalized request path (nav-v2 spec §3): same normalizer as the
            // page-cache key, so cached bodies and this value agree per path.
            'current_path' => $this->currentPath,
        ];
        if ($entry !== null) {
            $context['entry'] = $entry;
        }
        $context += $extra;
        // Every render carries a presentation context (spec §5a): entry-less
        // pages (listings, terms, errors) compose with no override.
        $context['presentation'] ??= $this->presentationContext(null, null);

        try {
            $html = $env->render($template, $context);
        } catch (\Throwable $e) {
            $this->logger->error('thallo-render: template render failed', [
                'template' => $template,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            if ($template === 'error.twig') {
                return new Response('Internal Server Error', 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }
            return $this->render('error.twig', $locale, null, 500, [], $twig, $assetBase);
        }

        $response = new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
        // Drain on SUCCESS only: tags collected by facets() during this render join the
        // page's Cache-Tag, so facet sidebars purge event-driven like everything else.
        $this->mergeCacheTags($response, $this->extension->drainTags());
        return $response;
    }

    /**
     * Term index pages (term-index spec §2–§3): the resolver classified the path
     * (thin kind); THIS side fetches counts and dispatches on the FacetCountsReader
     * invariant — empty cache_tags means a gate failed (unknown/non-filterable field,
     * non-visible type) and a VALID facet always carries tags, even with zero items.
     * hrefs are built HERE (the reader stays counts + tags): the term's archive path
     * with the same default-locale collapse the other rendered hrefs use.
     *
     * @param array<string,mixed> $result
     * @param array<string,mixed> $sessionExtra session banner context, when in a session
     * @param Environment|null $env request-local themed environment (themed sessions)
     */
    private function renderTerms(
        array $result,
        array $sessionExtra = [],
        ?Environment $env = null,
        ?string $assetBase = null,
    ): Response {
        // In-session gate failures render FRESH (spec §3) — never the shared fixed body.
        $notFound = $sessionExtra !== []
            ? fn (): Response => $this->render(
                '404.twig',
                $this->defaultLocale(),
                null,
                404,
                $sessionExtra,
                $env,
                $assetBase,
            )
            : fn (): Response => $this->errors->themed404(
                fn (): Response => $this->render('404.twig', $this->defaultLocale(), null, 404),
            );
        if ($this->facetReader === null) {
            return $notFound();
        }
        $typeSlug = (string) $result['type'];
        $field = (string) $result['field'];
        $locale = (string) $result['locale'];

        $counts = $this->facetReader->counts($typeSlug, $field, $locale, 500);
        if ($counts['cache_tags'] === []) {
            return $notFound(); // the pinned invariant: empty tags ⇔ gate failure
        }

        $prefix = $locale === $this->defaultLocale() ? '' : '/' . rawurlencode($locale);
        $terms = [];
        foreach ($counts['items'] as $item) {
            $slug = $item['slug'];
            $item['href'] = $slug === null
                ? null
                : $prefix . '/' . rawurlencode($typeSlug) . '/' . rawurlencode($field)
                    . '/' . rawurlencode($slug);
            $terms[] = $item;
        }

        $candidate = "terms/{$typeSlug}.twig";
        $template = ($env ?? $this->twig())->getLoader()->exists($candidate) ? $candidate : 'terms.twig';
        $response = $this->render($template, $locale, null, 200, $sessionExtra + [
            'terms' => $terms,
            'type' => $typeSlug,
            'field' => $field,
        ], $env, $assetBase);
        $this->mergeCacheTags($response, $counts['cache_tags']);
        return $response;
    }

    /**
     * Append-unique Cache-Tag merge: drained facet tags (set at render time) and the
     * caller-side taggers (tagResponse/tagCollection) must compose, so nobody may
     * blind-set the header.
     *
     * @param list<string> $tags
     */
    private function mergeCacheTags(Response $response, array $tags): void
    {
        if ($tags === []) {
            return;
        }
        $existing = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $response->headers->get('Cache-Tag', '')),
        )));
        $response->headers->set(
            'Cache-Tag',
            implode(', ', array_values(array_unique([...$existing, ...$tags]))),
        );
    }

    private function homepageConfigFailure(string $configured): Response
    {
        $error = new HomepageConfigError(
            "render.homepage_entry (\"{$configured}\") does not resolve to published, routed content.",
        );
        // Always logged; the message reaches the BODY only in debug mode (never leak in prod).
        $this->logger->error('thallo-render: ' . $error->getMessage());
        $debug = (bool) config($this->context, 'app.debug', false);
        return new Response(
            $debug ? $error->getMessage() : 'Internal Server Error',
            500,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    private function twig(): Environment
    {
        return $this->twig ??= $this->twigFactory->environment();
    }

    private function defaultLocale(): string
    {
        return (string) config($this->context, 'i18n.default_locale', 'en');
    }
}
