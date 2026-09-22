<?php

declare(strict_types=1);

namespace Thallo\Render;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Delivery\SiteVersionProvider;
use Thallo\Contracts\Settings\SiteNameProvider;

/**
 * The `site` template variable, built in one place for every page a theme renders — the site, the
 * shop, the account pages, fragments: the site name (Settings › General, through SiteNameProvider),
 * the locale, and the installed Thallo version (`site.version`, null in a development checkout).
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
            'name' => self::name($context),
            'locale' => $locale,
            'locales' => [],
            'version' => $version,
        ];
    }

    /** The site's name as visitors see it. */
    public static function name(ApplicationContext $context): string
    {
        $container = container($context);
        return $container->has(SiteNameProvider::class)
            ? $container->get(SiteNameProvider::class)->siteName()
            : 'Thallo';
    }
}
