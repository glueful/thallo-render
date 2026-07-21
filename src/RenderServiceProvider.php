<?php

declare(strict_types=1);

namespace Thallo\Render;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Glueful\Extensions\ServiceProvider;
use Thallo\Render\Templates\CustomCssUrl;
use Thallo\Render\Templates\DatabaseTemplateLoader;
use Thallo\Render\Templates\IconSet;
use Thallo\Render\Templates\IconInventory;
use Thallo\Render\Templates\TemplateLinter;
use Thallo\Render\Templates\TemplateRepository;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Content\BlockEditableFieldResolver;
use Thallo\Contracts\Content\FormSealer;
use Thallo\Contracts\Content\RegionReader;
use Thallo\Contracts\Content\RichHtmlSanitizer;
use Thallo\Contracts\Delivery\MediaUrlResolver;
use Thallo\Contracts\Settings\SiteFaviconProvider;
use Thallo\Contracts\Settings\SiteLogoProvider;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;
use Thallo\Contracts\Settings\ThemeChanged;
use Thallo\Contracts\Settings\ThemeSettingProvider;
use Thallo\Contracts\Delivery\EntryListReader;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Delivery\FacetCountsReader;
use Thallo\Contracts\Delivery\PreviewThemeValidator;
use Thallo\Contracts\Navigation\MenuReader;
use Thallo\Contracts\Content\RegionUpdated;
use Thallo\Contracts\Navigation\MenuUpdated;
use Thallo\Render\Console\ClearRenderCacheCommand;
use Thallo\Render\Contribution\RenderContributionRegistry;
use Thallo\Tenancy\Cache\TenantCacheSegment;
use Thallo\Render\Console\ThemeCloneCommand;
use Thallo\Contracts\Delivery\PreviewSessionVerifier;
use Thallo\Contracts\Delivery\StorefrontLinkResolver;
use Thallo\Render\Http\Controllers\RenderController;
use Thallo\Render\Http\Controllers\TemplatesAdminController;
use Thallo\Render\Templates\TemplateCatalog;
use Thallo\Render\Http\Middleware\PreviewSessionMiddleware;
use Thallo\Render\Http\Middleware\RenderPageCache;
use Thallo\Render\Listeners\PurgeRenderCacheOnMenuUpdate;
use Thallo\Render\Listeners\PurgeRenderCacheOnRegionUpdate;
use Thallo\Render\Listeners\PurgeRenderCacheOnTemplateUpdate;
use Thallo\Render\Listeners\PurgeRenderCacheOnAppearanceChange;
use Thallo\Render\Listeners\PurgeRenderCacheOnThemeChange;
use Thallo\Render\Templates\TemplateUpdated;
use Thallo\Render\Templates\ThemeCloner;
use Psr\Container\ContainerInterface;

use function config;

final class RenderServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            // Reserved-path + template-path contributor seam (storefront-rendering spec §5) —
            // bound SHARED and UNCONDITIONALLY (mirrors AdoptionContributorRegistry in
            // thallo-tenancy): ReservedPaths and ThemeLocator consult it regardless of whether
            // any pack has registered a contributor, so zero registrations stay a no-op.
            RenderContributionRegistry::class => [
                'class' => RenderContributionRegistry::class,
                'shared' => true,
                'autowire' => true,
            ],
            ThemeLocator::class => [
                'shared' => true,
                'factory' => [self::class, 'makeThemeLocator'],
            ],
            ActiveThemeSource::class => [
                'shared' => true,
                'factory' => [self::class, 'makeActiveThemeSource'],
            ],
            ThemeAppearanceSource::class => [
                'shared' => true,
                'factory' => [self::class, 'makeThemeAppearanceSource'],
            ],
            PurgeRenderCacheOnThemeChange::class => [
                'shared' => true,
                'factory' => [self::class, 'makePurgeRenderCacheOnThemeChange'],
            ],
            PurgeRenderCacheOnAppearanceChange::class => [
                'shared' => true,
                'factory' => [self::class, 'makePurgeRenderCacheOnAppearanceChange'],
            ],
            RenderContextExtension::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderContextExtension'],
            ],
            TwigFactory::class => [
                'shared' => true,
                'factory' => [self::class, 'makeTwigFactory'],
            ],
            // Commerce-Slice-2 Fix B: the route-less entry-blocks render seam. Autowired —
            // its only dependencies (PublishedEntryBlocksReader, TwigFactory,
            // RenderContextExtension) are all container-resolvable.
            EntryBlocksRenderer::class => [
                'class' => EntryBlocksRenderer::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReservedPaths::class => [
                'shared' => true,
                'factory' => [self::class, 'makeReservedPaths'],
            ],
            RenderController::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderController'],
            ],
            RenderPageCache::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderPageCache'],
            ],
            RenderErrorCache::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderErrorCache'],
            ],
            PurgeRenderCacheOnMenuUpdate::class => [
                'shared' => true,
                'factory' => [self::class, 'makePurgeRenderCacheOnMenuUpdate'],
            ],
            PurgeRenderCacheOnRegionUpdate::class => [
                'shared' => true,
                'factory' => [self::class, 'makePurgeRenderCacheOnRegionUpdate'],
            ],
            IconInventory::class => [
                'shared' => true,
                'factory' => [self::class, 'makeIconInventory'],
            ],
            ThemeCloner::class => [
                'shared' => true,
                'factory' => [self::class, 'makeThemeCloner'],
            ],
            ThemeCloneCommand::class => [
                'shared' => true,
                'factory' => [self::class, 'makeThemeCloneCommand'],
            ],
            ClearRenderCacheCommand::class => [
                'shared' => true,
                'factory' => [self::class, 'makeClearRenderCacheCommand'],
            ],
            PreviewThemeValidator::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderThemeValidator'],
            ],
            PreviewSessionMiddleware::class => [
                'shared' => true,
                'factory' => [self::class, 'makePreviewSessionMiddleware'],
            ],
            TemplateRepository::class => [
                'shared' => true,
                'factory' => [self::class, 'makeTemplateRepository'],
            ],
            TemplateLinter::class => [
                'shared' => true,
                'factory' => [self::class, 'makeTemplateLinter'],
            ],
            PurgeRenderCacheOnTemplateUpdate::class => [
                'shared' => true,
                'factory' => [self::class, 'makePurgeRenderCacheOnTemplateUpdate'],
            ],
            TemplateCatalog::class => [
                'shared' => true,
                'factory' => [self::class, 'makeTemplateCatalog'],
            ],
            TemplatesAdminController::class => [
                'shared' => true,
                'factory' => [self::class, 'makeTemplatesAdminController'],
            ],
        ];
    }

    public static function makeTemplateCatalog(ContainerInterface $container): TemplateCatalog
    {
        $context = $container->get(ApplicationContext::class);
        return new TemplateCatalog(
            $container->get(TemplateRepository::class),
            $context->getBasePath() . '/themes',
            dirname(__DIR__) . '/themes',
        );
    }

    public static function makeTemplatesAdminController(ContainerInterface $container): TemplatesAdminController
    {
        return new TemplatesAdminController(
            $container->get(TemplateRepository::class),
            $container->get(TemplateLinter::class),
            $container->get(TemplateCatalog::class),
            $container->get(PreviewThemeValidator::class),
            $container->get(ThemeLocator::class),
            $container->get(EventService::class),
            $container->get(ApplicationContext::class),
            $container->get(ThemeCloner::class),
        );
    }

    public static function makePurgeRenderCacheOnTemplateUpdate(
        ContainerInterface $container,
    ): PurgeRenderCacheOnTemplateUpdate {
        return new PurgeRenderCacheOnTemplateUpdate($container);
    }

    public static function makeTemplateRepository(ContainerInterface $container): TemplateRepository
    {
        return new TemplateRepository($container->get(Connection::class));
    }

    public static function makeTemplateLinter(ContainerInterface $container): TemplateLinter
    {
        return new TemplateLinter($container->get(RenderContextExtension::class));
    }

    public static function makePreviewSessionMiddleware(
        ContainerInterface $container,
    ): PreviewSessionMiddleware {
        return new PreviewSessionMiddleware($container->get(PreviewSessionVerifier::class));
    }

    public static function makeRenderThemeValidator(ContainerInterface $container): RenderThemeValidator
    {
        $context = $container->get(ApplicationContext::class);
        return new RenderThemeValidator($context->getBasePath() . '/themes');
    }

    public static function makeThemeCloner(ContainerInterface $container): ThemeCloner
    {
        $context = $container->get(ApplicationContext::class);
        return new ThemeCloner(
            $context->getBasePath() . '/themes',
            dirname(__DIR__) . '/themes',
            $container->get(PreviewThemeValidator::class),
        );
    }

    public static function makeThemeCloneCommand(ContainerInterface $container): ThemeCloneCommand
    {
        return new ThemeCloneCommand($container->get(ThemeCloner::class));
    }

    public static function makeClearRenderCacheCommand(
        ContainerInterface $container,
    ): ClearRenderCacheCommand {
        return new ClearRenderCacheCommand($container->get(CacheStore::class));
    }

    public static function makePurgeRenderCacheOnMenuUpdate(
        ContainerInterface $container,
    ): PurgeRenderCacheOnMenuUpdate {
        return new PurgeRenderCacheOnMenuUpdate($container);
    }

    public static function makePurgeRenderCacheOnRegionUpdate(
        ContainerInterface $container,
    ): PurgeRenderCacheOnRegionUpdate {
        return new PurgeRenderCacheOnRegionUpdate($container);
    }

    public static function makeIconInventory(ContainerInterface $container): IconInventory
    {
        return new IconInventory(dirname(__DIR__) . '/resources/icons');
    }

    public static function makeRenderErrorCache(ContainerInterface $container): RenderErrorCache
    {
        $context = $container->get(ApplicationContext::class);
        $appearance = $container->get(ThemeAppearanceSource::class);
        return new RenderErrorCache(
            $container->get(CacheStore::class),
            $container->get(ThemeLocator::class)->activePaths()['name'],
            $appearance->accent() . '-' . $appearance->neutral(),
            (bool) config($context, 'render.cache_enabled', true),
            (int) config($context, 'render.cache_ttl', 3600),
            $container->get(TenantCacheSegment::class),
            $context,
        );
    }

    public static function makeRenderPageCache(ContainerInterface $container): RenderPageCache
    {
        $context = $container->get(ApplicationContext::class);
        $appearance = $container->get(ThemeAppearanceSource::class);
        return new RenderPageCache(
            // The SAME binding InvalidateCacheTagsListener invalidates (spec §3 pin) —
            // this identity is what makes zero-new-purge-code true.
            $container->get(CacheStore::class),
            $container->get(ThemeLocator::class)->activePaths()['name'],
            $appearance->accent() . '-' . $appearance->neutral(),
            (bool) config($context, 'render.cache_enabled', true),
            (int) config($context, 'render.cache_ttl', 3600),
            $container->get(TenantCacheSegment::class),
            $context,
        );
    }

    public static function makeRenderController(ContainerInterface $container): RenderController
    {
        $context = $container->get(ApplicationContext::class);
        $dbTemplates = (bool) config($context, 'render.db_templates', true);
        return new RenderController(
            $context,
            $container->get(\Thallo\Contracts\Delivery\PublicRouteResolver::class),
            $container->get(TwigFactory::class),
            $container->get(RenderContextExtension::class),
            $container->get(ReservedPaths::class),
            $container->get(RenderErrorCache::class),
            $container->get(\Psr\Log\LoggerInterface::class),
            $container->has(FacetCountsReader::class)
                ? $container->get(FacetCountsReader::class)
                : null,
            $container->has(PreviewSessionVerifier::class)
                ? $container->get(PreviewSessionVerifier::class)
                : null,
            $dbTemplates ? $container->get(TemplateRepository::class) : null,
            $dbTemplates ? $container->get(TemplateLinter::class) : null,
            $container->get(ThemeLocator::class),
            $container->has(\Thallo\Contracts\Delivery\HomepageEntryProvider::class)
                ? $container->get(\Thallo\Contracts\Delivery\HomepageEntryProvider::class)
                : null,
            $container->has(EntryTargetResolver::class)
                ? $container->get(EntryTargetResolver::class)
                : null,
            $container->has(\Thallo\Contracts\Settings\AdminUrlProvider::class)
                ? $container->get(\Thallo\Contracts\Settings\AdminUrlProvider::class)
                : null,
            $container->get(TenantCacheSegment::class),
            // Fix C (Commerce-Slice-2 review): themedEnv() must build the preview-session
            // ThemeLocator with the SAME frozen contributed template dirs makeThemeLocator()
            // above uses — otherwise a pack-contributed template vanishes from a
            // non-default-theme preview.
            $container->get(RenderContributionRegistry::class),
        );
    }

    public static function makeThemeLocator(ContainerInterface $container): ThemeLocator
    {
        $context = $container->get(ApplicationContext::class);
        // Theme-setting spec §2: the NAME comes from the per-request source
        // (stored override -> env -> default); ThemeLocator's own ladder
        // (missing dir silent fallback, broken PRESENT dir loud) is untouched.
        // Contribution spec §5.2: this is the first place the registry is typically consumed —
        // its frozen*() read here (or in makeReservedPaths(), whichever the container resolves
        // first) takes the ONE snapshot for both reserved paths and template dirs. Both factories
        // are lazy DI factories, only invoked when a request actually needs the service — always
        // after every provider's register()+boot() has completed.
        return new ThemeLocator(
            $container->get(ActiveThemeSource::class)->name(),
            $context->getBasePath() . '/themes',
            null,
            $container->get(RenderContributionRegistry::class)->frozenTemplatePaths(),
        );
    }

    public static function makeThemeAppearanceSource(ContainerInterface $container): ThemeAppearanceSource
    {
        return new ThemeAppearanceSource(
            $container->has(\Thallo\Contracts\Settings\ThemeAppearanceProvider::class)
                ? $container->get(\Thallo\Contracts\Settings\ThemeAppearanceProvider::class)
                : null,
            $container->get(\Psr\Log\LoggerInterface::class),
        );
    }

    public static function makeActiveThemeSource(ContainerInterface $container): ActiveThemeSource
    {
        $context = $container->get(ApplicationContext::class);
        return new ActiveThemeSource(
            // Soft-bound: the app's settings engine may be absent.
            $container->has(ThemeSettingProvider::class)
                ? $container->get(ThemeSettingProvider::class)
                : null,
            $container->get(PreviewThemeValidator::class),
            (string) config($context, 'render.theme', 'default'),
            $container->get(\Psr\Log\LoggerInterface::class),
        );
    }

    public static function makePurgeRenderCacheOnThemeChange(
        ContainerInterface $container,
    ): PurgeRenderCacheOnThemeChange {
        return new PurgeRenderCacheOnThemeChange($container);
    }

    public static function makePurgeRenderCacheOnAppearanceChange(
        ContainerInterface $container,
    ): PurgeRenderCacheOnAppearanceChange {
        return new PurgeRenderCacheOnAppearanceChange($container);
    }

    public static function makeRenderContextExtension(ContainerInterface $container): RenderContextExtension
    {
        $context = $container->get(ApplicationContext::class);
        // MenuReader is OPTIONAL — render has no hard dependency on thallo-navigation.
        $menus = $container->has(MenuReader::class) ? $container->get(MenuReader::class) : null;
        // FacetCountsReader is likewise soft: no binding means facets() returns [].
        $facets = $container->has(FacetCountsReader::class)
            ? $container->get(FacetCountsReader::class)
            : null;
        return new RenderContextExtension(
            $menus instanceof MenuReader ? $menus : null,
            $container->get(EntryTargetResolver::class),
            (string) config($context, 'i18n.default_locale', 'en'),
            $facets instanceof FacetCountsReader ? $facets : null,
            // blocks() diagnostics (block-builder spec §6): provider-injected, never
            // read from Twig context.
            $container->get(\Psr\Log\LoggerInterface::class),
            (bool) config($context, 'app.debug', false),
            // safe_html (sanitizer spec §4): soft-bound; null fails CLOSED (escapes).
            $container->has(RichHtmlSanitizer::class)
                ? $container->get(RichHtmlSanitizer::class)
                : null,
            // media() (starter-library spec §3): soft-bound; null = always-null URLs.
            $container->has(MediaUrlResolver::class)
                ? $container->get(MediaUrlResolver::class)
                : null,
            // Edit-in-place marking (spec §2): soft-bound; null = never marks.
            $container->has(BlockEditableFieldResolver::class)
                ? $container->get(BlockEditableFieldResolver::class)
                : null,
            // site_logo() (block-library spec §2): soft-bound; null = no logo.
            $container->has(SiteLogoProvider::class)
                ? $container->get(SiteLogoProvider::class)
                : null,
            // icon() (icon-library spec): pack-internal furniture — fixed
            // resources root, no app-side contract to soft-bind.
            new IconSet(dirname(__DIR__) . '/resources/icons'),
            // region_blocks()/region_settings() (global-regions spec): soft-bound;
            // null = fallback chrome everywhere.
            $container->has(RegionReader::class)
                ? $container->get(RegionReader::class)
                : null,
            // site_favicon() (site-identity spec): soft-bound; null = no link tag.
            $container->has(SiteFaviconProvider::class)
                ? $container->get(SiteFaviconProvider::class)
                : null,
            // custom_css() (custom-css spec): pack-internal — repo + active theme.
            new CustomCssUrl(
                $container->get(TemplateRepository::class),
                $container->get(ThemeLocator::class),
            ),
            // asset() theme buster (theme-setting spec §3).
            $container->get(ActiveThemeSource::class),
            // color-mode spec §3.4: gates the resolver, marker, and toggle block.
            colorModeEnabled: (bool) config($context, 'theme.color_mode.enabled', true),
            // theme-color-config spec §4: the saved/default accent-neutral source.
            appearance: $container->get(ThemeAppearanceSource::class),
            // asset() content fingerprint (theme-setting spec §3 P1): the active theme's
            // assets dir — the SAME dir themeAsset() serves from — so ?v=<mtime> matches
            // the file the browser actually fetches.
            themeAssetsDir: $container->get(ThemeLocator::class)->activePaths()['assets'],
            // entries() (blog-posts spec): soft-bound; null = [] (block renders nothing).
            entryReader: $container->has(EntryListReader::class)
                ? $container->get(EntryListReader::class)
                : null,
            // form_render() (form-block spec §4): soft-bound; null = disabled notice.
            formSealer: $container->has(FormSealer::class)
                ? $container->get(FormSealer::class)
                : null,
            // shop_product_url()/shop_category_url()/shop_index_url() (Commerce-Slice-2 Fix A):
            // soft-bound; null = every helper returns null, blocks degrade to plain text.
            storefrontLinks: $container->has(StorefrontLinkResolver::class)
                ? $container->get(StorefrontLinkResolver::class)
                : null,
        );
    }

    public static function makeTwigFactory(ContainerInterface $container): TwigFactory
    {
        $context = $container->get(ApplicationContext::class);
        $db = null;
        if ((bool) config($context, 'render.db_templates', true)) {
            $db = new DatabaseTemplateLoader(
                $container->get(TemplateRepository::class),
                $container->get(TemplateLinter::class),
                // The RESOLVED active theme (activePaths()['name']) — matches the page
                // cache's theme keying; a fallen-back locator must not apply another
                // theme's overrides.
                $container->get(ThemeLocator::class)->activePaths()['name'],
                $container->get(TenantCacheSegment::class),
                $context,
            );
        }
        return new TwigFactory(
            $container->get(ThemeLocator::class),
            $container->get(RenderContextExtension::class),
            $context->getBasePath() . '/storage/cache/twig',
            $db,
        );
    }

    public static function makeReservedPaths(ContainerInterface $container): ReservedPaths
    {
        $context = $container->get(ApplicationContext::class);
        // Contribution spec §5.1: config-declared prefixes/exacts first, contributed ones
        // appended (frozen*() read — see makeThemeLocator()'s note on WHEN the freeze happens).
        // Zero contributors → frozenReserved() returns empty lists → byte-identical to before.
        $contributed = $container->get(RenderContributionRegistry::class)->frozenReserved();
        return new ReservedPaths(
            array_merge(
                array_values(array_map(strval(...), (array) config($context, 'render.reserved_prefixes', []))),
                $contributed['prefixes'],
            ),
            array_merge(
                array_values(array_map(strval(...), (array) config($context, 'render.reserved_exact', []))),
                $contributed['exacts'],
            ),
        );
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge the pack's tree under 'render'.
        $this->mergeConfig('render', require __DIR__ . '/../config/render.php');
    }

    public function boot(ApplicationContext $context): void
    {
        // OUTSIDE the capability gate (pack convention): schema must exist regardless
        // of whether rendered delivery is currently enabled.
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');

        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'thallo.render',
            label: 'Rendered delivery',
            description: 'Server-rendered pages from published content via filesystem Twig themes.',
        ));

        if ($registry->isEnabled('thallo.render')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/public-routes.php');

            // Theme assets are served DYNAMICALLY by RenderController::themeAsset
            // (theme-setting spec §3) — the old boot-time static mount froze the
            // theme into the compiled route manifest.

            // The pack's purge listeners (spec §4 + global-regions spec §11): menu and
            // region changes purge broadly — both can appear on any rendered page.
            // Entry/type purges need no render code — InvalidateCacheTagsListener
            // already invalidates the tags the middleware stores under.
            $events = app($context, EventService::class);
            $events->addListener(
                MenuUpdated::class,
                [app($context, PurgeRenderCacheOnMenuUpdate::class), 'onMenuUpdated'],
            );
            $events->addListener(
                RegionUpdated::class,
                [app($context, PurgeRenderCacheOnRegionUpdate::class), 'onRegionUpdated'],
            );

            // DB-edited templates (spec §5/§7): admin routes + purge listener only
            // when the feature is on — the kill-switch removes every
            // template-mutation pathway.
            if ((bool) config($context, 'render.db_templates', true)) {
                $this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');
                $events->addListener(
                    TemplateUpdated::class,
                    [app($context, PurgeRenderCacheOnTemplateUpdate::class), 'onTemplateUpdated'],
                );
            }

            // Live theme switch (theme-setting spec §5): the app's settings save
            // dispatches ThemeChanged; every page + themed error body purges.
            $events->addListener(
                ThemeChanged::class,
                [app($context, PurgeRenderCacheOnThemeChange::class), 'onThemeChanged'],
            );
            // Theme color config (theme-color-config spec §7): accent/neutral save
            // dispatches ThemeAppearanceChanged; purges every page + error body.
            $events->addListener(
                ThemeAppearanceChanged::class,
                [app($context, PurgeRenderCacheOnAppearanceChange::class), 'onAppearanceChanged'],
            );
        }

        // OUTSIDE the capability gate (analytics-pack precedent): an operator may need
        // to clear stale pages right after disabling the capability.
        $this->commands([ClearRenderCacheCommand::class, ThemeCloneCommand::class]);
    }
}
