<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Style\StyleSchema;
use Thallo\Contracts\Style\Vocabulary;

/**
 * A workspace's colours stylesheet (custom palette spec §3.4): for each configured brand id, its two
 * variables and the compiler's colour utilities for `brand-N` and `brand-N-contrast`, in
 * `@layer settings`. Values stay in variables, so the bytes depend only on which ids are configured:
 * renaming or re-colouring changes nothing here, adding or clearing a colour makes a new stylesheet.
 */
final class ColorsArtifact
{
    /** @param list<int> $ids */
    public static function compile(array $ids): string
    {
        $ids = self::sorted($ids);
        if ($ids === []) {
            return '';
        }
        $vars = '';
        $names = [];
        foreach ($ids as $id) {
            foreach (["brand-{$id}", "brand-{$id}-contrast"] as $name) {
                $vars .= '  ' . StyleCompiler::variable("color.{$name}") . ': '
                    . Vocabulary::siteControlled("color.{$name}") . ";\n";
                $names[] = $name;
            }
        }
        return "@layer settings {\n:root {\n{$vars}}\n" . StyleCompiler::colorUtilities($names) . "}\n";
    }

    /** 16 hex characters over the sorted ids and the versions that decide the bytes. */
    public static function hash(array $ids): string
    {
        return substr(hash('sha256', (string) json_encode([
            'ids' => self::sorted($ids),
            'compiler' => StyleCompiler::VERSION,
            'settings' => StyleSchema::VERSION,
        ])), 0, 16);
    }

    /** @param list<int> $ids @return list<int> */
    private static function sorted(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $ids;
    }
}
