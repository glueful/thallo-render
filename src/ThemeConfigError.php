<?php

declare(strict_types=1);

namespace Thallo\Render;

/** Invalid theme.json in a PRESENT app theme — operator error, loud 500 (spec §4). */
final class ThemeConfigError extends \RuntimeException
{
}
