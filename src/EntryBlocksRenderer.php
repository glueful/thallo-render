<?php

declare(strict_types=1);

namespace Thallo\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Delivery\PublishedEntryBlocksReader;

use function config;

/**
 * Route-less entry-blocks render seam (Commerce-Slice-2 Fix B): renders ONE entry's blocks
 * region by uuid, WITHOUT route resolution — the gap {@see \Thallo\Contracts\Delivery\
 * PublicRouteResolver::resolveEntry()} cannot cross for a route-less entry (it requires a live
 * `entry_routes` row and returns `not_found` otherwise). Consumed directly (in-process, no
 * internal HTTP) by any pack that links its own domain object to a content entry for
 * enrichment purposes — the shop product-detail page is the first caller, but nothing here is
 * shop-specific.
 *
 * Tenant-scoped and published-only via {@see PublishedEntryBlocksReader} (fails closed —
 * returns null — for a missing/deleted/cross-tenant/unpublished/non-public-type entry: the
 * caller's page renders without this enrichment, never a 500). Renders through the SAME
 * `blocks()` machinery {@see RenderContextExtension::blocks()} exposes to `entry.twig`'s own
 * `{{ blocks(entry.fields.body) }}` convention — called here as a plain PHP method, not via a
 * Twig template, since this seam has no template of its own; it only ever supplies ONE
 * region's markup to the CALLER's own template. The caller's own template selection is never
 * touched — this class returns a markup string, nothing that could reroute rendering.
 *
 * Explicitly resets every render-scoped flag {@see RenderContextExtension} carries as a
 * process-shared singleton BEFORE calling blocks() (asset base, block annotation, appearance
 * override, locale) so this seam is correct regardless of what the calling controller has or
 * hasn't already reset — it does not rely on the caller's own render() having run first.
 */
final class EntryBlocksRenderer
{
    public function __construct(
        private readonly PublishedEntryBlocksReader $reader,
        private readonly TwigFactory $twigFactory,
        private readonly RenderContextExtension $extension,
    ) {
    }

    /**
     * The linked entry's rendered blocks-region HTML, or null when the entry fails closed
     * (missing, soft-deleted, cross-tenant, non-public type, or no published version in the
     * resolved locale). An entry that resolves but carries no blocks-typed `body` field (or an
     * empty one) renders as an empty string, not null — that is a resolved-but-empty result,
     * distinct from a fail-closed one.
     */
    public function renderPublishedBlocks(
        ApplicationContext $context,
        string $tenantUuid,
        string $entryUuid,
    ): ?string {
        $locale = (string) config($context, 'i18n.default_locale', 'en');
        $result = $this->reader->findPublishedBlocks($entryUuid, $tenantUuid, $locale);
        if ($result === null) {
            return null;
        }

        $env = $this->twigFactory->environment();

        // Reset-before-render (mirrors RenderController::render() / ShopCatalogController::
        // render()'s identical discipline): the extension is a process-shared singleton, so
        // every render through it — including this one — must not inherit a previous render's
        // state.
        $this->extension->resetPerRenderState();
        $this->extension->setAssetBase(null);
        $this->extension->setBlockAnnotations(false);
        $this->extension->setThemeAppearanceOverride(null, null);
        $this->extension->setLocale($locale);

        $blockContext = [
            'entry' => ['uuid' => $result['entry_uuid'], 'fields' => $result['fields']],
            'site' => [
                'name' => (string) config($context, 'render.site_name', 'Thallo'),
                'locale' => $locale,
                'locales' => [],
            ],
            'current_path' => null,
            'region_slug' => null,
        ];

        return $this->extension->blocks($env, $blockContext, $result['fields']['body'] ?? null);
    }
}
