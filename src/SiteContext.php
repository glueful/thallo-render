<?php

declare(strict_types=1);

namespace Thallo\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Delivery\SiteVersionProvider;

/**
 * The `site` template variable, built in one place for the page render and for fragment renders:
 * the site name, the locale, and the installed Thallo version (`site.version`, null in a
 * development checkout) from the SiteVersionProvider contract when the application binds one.
 */
final class SiteContext
{
    /** @return array{name: string, locale: string, locales: list<string>, version: ?string} */
    public static function build(ApplicationContext $context, string $locale): array
    {
        $version = null;
        $container = container($context);
        if ($container->has(SiteVersionProvider::class)) {
            $version = $container->get(SiteVersionProvider::class)->installedVersion();
        }

        return [
            'name' => (string) config($context, 'render.site_name', 'Thallo'),
            'locale' => $locale,
            'locales' => [],
            'version' => $version,
        ];
    }
}
