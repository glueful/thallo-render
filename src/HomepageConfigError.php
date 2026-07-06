<?php

declare(strict_types=1);

namespace Thallo\Render;

/**
 * render.homepage_entry points at something that cannot render (missing,
 * unpublished, routeless, or deleted). Operator error — deliberately loud (500, never a
 * themed 404). Always logged; the message reaches the response body only in debug mode.
 */
final class HomepageConfigError extends \RuntimeException
{
}
