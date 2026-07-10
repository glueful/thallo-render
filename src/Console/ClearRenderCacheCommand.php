<?php

declare(strict_types=1);

namespace Thallo\Render\Console;

use Glueful\Cache\CacheStore;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clears the whole rendered-page cache (render:* keys — per-path pages AND the fixed
 * 404/410 bodies) via deletePattern — no tag support required, so it works on every
 * cache driver. The documented answer to "I edited the theme and still see old HTML":
 * a Redis-backed cache survives app restarts, so a restart does NOT imply a cold
 * cache (spec §6).
 */
#[AsCommand(
    name: 'render:cache:clear',
    description: 'Clear the rendered page cache (all render:* keys).',
)]
final class ClearRenderCacheCommand extends BaseCommand
{
    public function __construct(private readonly CacheStore $cache)
    {
        parent::__construct();
    }

    /** The testable unit: drop every render:* key. */
    public function clear(): bool
    {
        $legacy = $this->cache->deletePattern('render:*');
        $segmented = $this->cache->deletePattern('tenant:*:render:*');

        return $legacy && $segmented;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->clear();
        $this->success('Rendered page cache cleared (render:* keys).');
        return self::SUCCESS;
    }
}
