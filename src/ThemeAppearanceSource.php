<?php

declare(strict_types=1);

namespace Thallo\Render;

use Psr\Log\LoggerInterface;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Render\Theme\ThemeColors;

/**
 * The effective theme appearance (theme-color-config spec §4): saved accent/
 * neutral -> blue/slate, validated against the closed enums and memoized per
 * instance (per request in classic PHP). An out-of-enum stored value logs and
 * falls back to the default rather than emitting broken CSS.
 */
final class ThemeAppearanceSource
{
    private ?string $accentMemo = null;
    private ?string $neutralMemo = null;

    public function __construct(
        /** Soft-bound: null = no settings engine, default applies. */
        private readonly ?ThemeAppearanceProvider $settings,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function accent(): string
    {
        if ($this->accentMemo !== null) {
            return $this->accentMemo;
        }
        $raw = $this->settings?->accent() ?? ThemeColors::DEFAULT_ACCENT;
        $ok = ThemeColors::normalizeAccent($raw);
        if ($ok === null) {
            $this->logger?->warning("[Thallo] Invalid theme accent '{$raw}'; falling back to 'blue'.");
            $ok = ThemeColors::DEFAULT_ACCENT;
        }
        return $this->accentMemo = $ok;
    }

    public function neutral(): string
    {
        if ($this->neutralMemo !== null) {
            return $this->neutralMemo;
        }
        $raw = $this->settings?->neutral() ?? ThemeColors::DEFAULT_NEUTRAL;
        $ok = ThemeColors::normalizeNeutral($raw);
        if ($ok === null) {
            $this->logger?->warning("[Thallo] Invalid theme neutral '{$raw}'; falling back to 'slate'.");
            $ok = ThemeColors::DEFAULT_NEUTRAL;
        }
        return $this->neutralMemo = $ok;
    }
}
