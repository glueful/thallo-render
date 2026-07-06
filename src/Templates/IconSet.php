<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

/**
 * Vendored inline-SVG icon resolver (icon-library spec). Two fixed sets under
 * one root: lucide/ (default namespace) and brands/ (`brand:` prefix, curated
 * Simple Icons normalized to currentColor at vendoring). The strict name
 * grammar admits no dots or slashes, so a name can only ever select a file
 * inside the fixed roots — traversal is impossible by construction. Output is
 * the vendored markup plus exactly two injected attributes; anything invalid,
 * unknown, or unreadable is null so templates can fall back to text.
 */
final class IconSet
{
    private const GRAMMAR = '/\A(brand:)?[a-z0-9-]+\z/';

    /** @var array<string, string|null> per-process memo (null = known miss) */
    private array $memo = [];

    public function __construct(private readonly string $root)
    {
    }

    public function svg(string $name): ?string
    {
        if (array_key_exists($name, $this->memo)) {
            return $this->memo[$name];
        }
        if (preg_match(self::GRAMMAR, $name) !== 1) {
            return $this->memo[$name] = null;
        }
        $brand = str_starts_with($name, 'brand:');
        $file = $this->root . '/' . ($brand ? 'brands' : 'lucide') . '/'
            . ($brand ? substr($name, 6) : $name) . '.svg';
        if (!is_file($file)) {
            return $this->memo[$name] = null;
        }
        $raw = file_get_contents($file);
        if ($raw === false || !str_starts_with(ltrim($raw), '<svg')) {
            return $this->memo[$name] = null;
        }
        return $this->memo[$name] = $this->decorate(trim($raw));
    }

    /** Inject class="thallo-icon" (appended to an existing class) + aria-hidden into the opening tag. */
    private function decorate(string $svg): string
    {
        $end = strpos($svg, '>');
        if ($end === false) {
            return $svg;
        }
        $tag = substr($svg, 0, $end);
        if (preg_match('/class="([^"]*)"/', $tag, $m) === 1) {
            $tag = str_replace($m[0], 'class="' . $m[1] . ' thallo-icon"', $tag);
        } else {
            $tag .= ' class="thallo-icon"';
        }
        if (!str_contains($tag, 'aria-hidden=')) {
            $tag .= ' aria-hidden="true"';
        }
        return $tag . substr($svg, $end);
    }
}
