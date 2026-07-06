<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

/**
 * The vendored icon inventory (icon-picker spec §1): what the admin picker
 * may offer is EXACTLY what icon() can render — sourced from the shipped
 * directories, never from client-side icon-set assumptions. Per-process memo;
 * the inventory only changes on deploy, so there is no invalidation surface.
 */
final class IconInventory
{
    public const SETS = ['lucide', 'brands'];

    /** @var array<string, list<string>> */
    private array $memo = [];

    public function __construct(private readonly string $root)
    {
    }

    /** @return list<string>|null null = unknown set */
    public function names(string $set): ?array
    {
        if (!in_array($set, self::SETS, true)) {
            return null;
        }
        if (!isset($this->memo[$set])) {
            $files = glob($this->root . '/' . $set . '/*.svg') ?: [];
            $names = array_map(static fn (string $f): string => basename($f, '.svg'), $files);
            sort($names);
            $this->memo[$set] = array_values($names);
        }
        return $this->memo[$set];
    }

    /**
     * The raw vendored SVGs keyed by name — the admin picker's PREVIEW source
     * for sets the admin's own icon pipeline can't render (brands). Vendored
     * + review-gated markup (IconAssetsTest), same trust basis as icon().
     * Intended for SMALL sets; callers decide when to ask.
     *
     * @return array<string, string>|null null = unknown set
     */
    public function svgs(string $set): ?array
    {
        $names = $this->names($set);
        if ($names === null) {
            return null;
        }
        $out = [];
        foreach ($names as $name) {
            $svg = file_get_contents($this->root . '/' . $set . '/' . $name . '.svg');
            if ($svg !== false) {
                $out[$name] = $svg;
            }
        }
        return $out;
    }
}
