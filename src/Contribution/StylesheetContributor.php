<?php

declare(strict_types=1);

namespace Thallo\Render\Contribution;

/**
 * A package's selector-bearing stylesheets (visual builder spec §2.2): delivered by Thallo
 * inside `@layer theme` after the active theme's own manifest, so package rules never escape
 * the theme layer and never compete with managed settings. Registered during provider boot,
 * frozen with the other contributions on first read.
 */
interface StylesheetContributor
{
    public function contributorId(): string;

    public function priority(): int;

    /** @return list<string> absolute paths, in delivery order */
    public function stylesheets(): array;
}
