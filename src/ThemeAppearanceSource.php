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
        /** The fonts stylesheet's hash (block typeface spec §3.4), lazily; '' when there is none. */
        private readonly ?\Closure $fontsArtifactHash = null,
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
        // `custom` (custom palette spec §2) is the palette's own neutral: themeColorsStyle() resolves it.
        $ok = $raw === 'custom' ? 'custom' : ThemeColors::normalizeNeutral($raw);
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
     * Custom's Text and Headings as font library IDs; a value that is not an ID is no family.
     *
     * @return array{text?: string, headings?: string}
     */
    public function fontFamilies(): array
    {
        $families = [];
        foreach (['text', 'headings'] as $role) {
            $id = ($this->settings?->fontFamilies() ?? [])[$role] ?? null;
            if (is_string($id) && ThemeDesign::normalizeFamily($id) !== null) {
                $families[$role] = $id;
            }
        }
        return $families;
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
        return implode('-', $this->segments(true));
    }

    /**
     * What a stage's head depends on (block typeface plan Task 11): the fingerprint without the style
     * class generation, which has its own carrier (`style_generation`) and is re-resolved in place —
     * a class edit never needs a stage reload.
     */
    public function appearanceFingerprint(): string
    {
        return implode('-', $this->segments(false));
    }

    /**
     * Drops what this instance memoised, for a caller that must read the stored appearance now —
     * a freshness check in a worker that outlives one request.
     */
    public function forget(): void
    {
        $this->accentMemo = $this->neutralMemo = $this->radiusMemo = $this->fontMemo = $this->backgroundMemo = null;
    }

    /** @return list<string> */
    private function segments(bool $withStyleGeneration): array
    {
        $segments = [$this->accent(), $this->neutral(), $this->radius(), $this->font(), $this->background()];
        // Custom's families re-key every cached page; with none, the fingerprint it always had.
        $families = $this->fontFamilies();
        if ($families !== []) {
            $segments[] = 'f' . ($families['text'] ?? '') . '.' . ($families['headings'] ?? '');
        }
        if ($this->themeArtifactHash !== null) {
            $segments[] = 't' . substr((string) ($this->themeArtifactHash)(), 0, 8);
        }
        if ($this->settingsArtifactHash !== null) {
            $segments[] = 's' . substr((string) ($this->settingsArtifactHash)(), 0, 8);
        }
        if ($withStyleGeneration && $this->styleGeneration !== null) {
            $segments[] = 'g' . (int) ($this->styleGeneration)();
        }
        // A library change re-keys every cached page; with no current family, the fingerprint it had.
        $fonts = $this->fontsArtifactHash !== null ? (string) ($this->fontsArtifactHash)() : '';
        if ($fonts !== '') {
            $segments[] = 'l' . substr($fonts, 0, 8);
        }
        return $segments;
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
