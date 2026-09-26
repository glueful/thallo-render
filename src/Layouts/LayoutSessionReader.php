<?php

declare(strict_types=1);

namespace Thallo\Render\Layouts;

use Thallo\Contracts\Layouts\LayoutReader;

/**
 * The layout stage's reader (type layouts spec §5.4): answers the session's working copy (else its
 * baseline) for the layout being edited, and asks the real reader for every other.
 */
final class LayoutSessionReader implements LayoutReader
{
    /**
     * @param array{layout: array{blocks: list<array<string,mixed>>, settings: array<string,mixed>},
     *     lock_version: int, surface: string, target: string} $snapshot
     */
    public function __construct(
        private readonly array $snapshot,
        private readonly ?LayoutReader $delegate = null,
    ) {
    }

    public function for(string $surface, string $target): ?array
    {
        if ($surface === $this->snapshot['surface'] && $target === $this->snapshot['target']) {
            return [
                'blocks' => $this->snapshot['layout']['blocks'],
                'settings' => $this->snapshot['layout']['settings'],
                'lock_version' => $this->snapshot['lock_version'],
            ];
        }
        return $this->delegate?->for($surface, $target);
    }
}
