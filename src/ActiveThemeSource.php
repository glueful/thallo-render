<?php

declare(strict_types=1);

namespace Thallo\Render;

use Thallo\Contracts\Delivery\PreviewThemeValidator;
use Thallo\Contracts\Settings\ThemeSettingProvider;
use Psr\Log\LoggerInterface;

/**
 * The effective live theme (theme-setting spec §2): stored override →
 * RENDER_THEME env → 'default', memoized per instance (per request in
 * classic PHP). The override is REVALIDATED on every resolution — a row whose
 * theme directory was deleted or whose theme.json broke since the save logs
 * and falls back; a stale row can never reach ThemeLocator's throwing path.
 * The env ladder itself is untouched: ThemeLocator still silently falls back
 * on a MISSING env theme dir and still throws ThemeConfigError on a PRESENT
 * env dir with broken theme.json.
 */
final class ActiveThemeSource
{
    private ?string $memo = null;

    public function __construct(
        /** Soft-bound (spec §2): null = no settings engine, env/config only. */
        private readonly ?ThemeSettingProvider $settings,
        private readonly PreviewThemeValidator $validator,
        private readonly string $envTheme,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function name(): string
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $override = $this->settings?->themeOverride();
        if ($override !== null && $override !== '') {
            if ($this->validator->isValidTheme($override)) {
                return $this->memo = $override;
            }
            $this->logger?->warning(
                "[Thallo] Stored theme '{$override}' is no longer valid; falling back to '{$this->envTheme}'.",
            );
        }
        return $this->memo = $this->envTheme;
    }
}
