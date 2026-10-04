<?php

declare(strict_types=1);

namespace Thallo\Render\Contribution;

/**
 * A package's per-block scripts, by name (search block spec §3.1): a block template asks for one
 * with `block_script(name)`, which emits its deferred tag once per render at the URL given here —
 * already fingerprinted, so a page never pays a redirect. Registered during provider register(),
 * frozen with the other contributions on first read. A name another package already contributed is
 * rejected at freeze; a name core's own catalog uses is never served from here — core's script wins.
 */
interface BlockScriptContributor
{
    public function contributorId(): string;

    public function priority(): int;

    /** @return array<string, string> script name => same-origin URL */
    public function blockScripts(): array;
}
