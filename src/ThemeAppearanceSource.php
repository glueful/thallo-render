<?php

declare(strict_types=1);

namespace Thallo\Render;

use Psr\Log\LoggerInterface;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Render\Theme\ThemeColors;
use Thallo\Render\Theme\ThemeDesign;

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
    private ?string $radiusMemo = null;
    private ?string $fontMemo = null;
    private ?string $backgroundMemo = null;

    public function __construct(
        /** Soft-bound: null = no settings engine, default applies. */
        private readonly ?ThemeAppearanceProvider $settings,
        private readonly ?LoggerInterface $logger = null,
        /** Layered delivery (spec §2.4): the active theme artifact's content hash, lazily. */
        private readonly ?\Closure $themeArtifactHash = null,
        /** The compiled style artifact's hash (spec §2.4), lazily: a recompile re-keys every page. */
        private readonly ?\Closure $settingsArtifactHash = null,
        /** The style class generation (spec §4.3), lazily: the request's snapshot names the entry. */
        private readonly ?\Closure $styleGeneration = null,
    ) {
    }

    public function accent(): string
    {
        if ($this->accentMemo !== null) {
            return $this->accentMemo;
        }
        $raw = $this->settings?->accent() ?? ThemeColors::DEFAULT_ACCENT;
        $ok = ThemeColors::normalizeSiteAccent($raw);
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

    public function radius(): string
    {
        return $this->radiusMemo ??= $this->design(
            $this->settings?->radius(),
            ThemeDesign::normalizeRadius(...),
            ThemeDesign::DEFAULT_RADIUS,
            'radius',
        );
    }

    public function font(): string
    {
        return $this->fontMemo ??= $this->design(
            $this->settings?->font(),
            ThemeDesign::normalizeFont(...),
            ThemeDesign::DEFAULT_FONT,
            'font',
        );
    }

    /**
     * The site's own faces as media library uuids; a value that is not one is not a face.
     *
     * @return array{body?: string, display?: string}
     */
    public function fontFaces(): array
    {
        $faces = [];
        foreach (['body', 'display'] as $role) {
            $uuid = ($this->settings?->fontFaces() ?? [])[$role] ?? null;
            if (is_string($uuid) && ThemeDesign::normalizeFace($uuid) !== null) {
                $faces[$role] = $uuid;
            }
        }
        return $faces;
    }

    public function background(): string
    {
        return $this->backgroundMemo ??= $this->design(
            $this->settings?->background(),
            ThemeDesign::normalizeBackground(...),
            ThemeDesign::DEFAULT_BACKGROUND,
            'background',
        );
    }

    /**
     * Every validated appearance choice, joined: the render cache's appearance segment
     * (theme-color-config spec §7), so any change re-keys every cached page.
     */
    public function fingerprint(): string
    {
        $segments = [$this->accent(), $this->neutral(), $this->radius(), $this->font(), $this->background()];
        // The site's own faces re-key every cached page; with none, the fingerprint it always had.
        if ($this->fontFaces() !== []) {
            $segments[] = 'f' . implode('.', $this->fontFaces());
        }
        if ($this->themeArtifactHash !== null) {
            $segments[] = 't' . substr((string) ($this->themeArtifactHash)(), 0, 8);
        }
        if ($this->settingsArtifactHash !== null) {
            $segments[] = 's' . substr((string) ($this->settingsArtifactHash)(), 0, 8);
        }
        if ($this->styleGeneration !== null) {
            $segments[] = 'g' . (int) ($this->styleGeneration)();
        }
        return implode('-', $segments);
    }

    /** @param callable(string):?string $normalize */
    private function design(?string $raw, callable $normalize, string $default, string $what): string
    {
        $raw ??= $default;
        $ok = $normalize($raw);
        if ($ok === null) {
            $this->logger?->warning("[Thallo] Invalid theme {$what} '{$raw}'; falling back to '{$default}'.");
            $ok = $default;
        }
        return $ok;
    }
}
