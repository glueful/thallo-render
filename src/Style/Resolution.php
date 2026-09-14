<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

/**
 * The resolver's answer for one property at one breakpoint (visual builder spec §1.6, §3.4):
 * a managed value with its source and state, or the `theme-default` sentinel. The resolver never
 * returns a concrete theme value; the browser exposes it through the theme layer.
 */
final readonly class Resolution
{
    /**
     * @param array<string,mixed>|null $value the typed value, null for theme-default and reset
     * @param string $source `instance`, `class:<id>` or `theme-default`
     * @param string $state `explicit`, `inherited`, `theme-default` or `reset`
     */
    private function __construct(
        public ?array $value,
        public string $source,
        public string $state,
        public string $breakpoint,
    ) {
    }

    /** @param array<string,mixed> $value */
    public static function managed(array $value, string $layer, bool $exact, string $breakpoint): self
    {
        return new self($value, $layer, $exact ? 'explicit' : 'inherited', $breakpoint);
    }

    public static function reset(string $layer, string $breakpoint): self
    {
        return new self(null, $layer, 'reset', $breakpoint);
    }

    public static function themeDefault(string $breakpoint): self
    {
        return new self(null, 'theme-default', 'theme-default', $breakpoint);
    }

    public function isManaged(): bool
    {
        return $this->value !== null;
    }

    /** @return array{value: array<string,mixed>|null, source: string, state: string} */
    public function toArray(): array
    {
        return ['value' => $this->value, 'source' => $this->source, 'state' => $this->state];
    }
}
