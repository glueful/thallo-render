<?php

declare(strict_types=1);

namespace Thallo\Render\Style;

use Thallo\Contracts\Style\StyleSchema;
use Thallo\Contracts\Style\ValueKind;
use Thallo\Contracts\Style\Vocabulary;

/**
 * Compiles a theme's vocabulary into the compiled style artifact (visual builder spec §2.4):
 * `@layer settings` holding the `--t-*` custom properties, one utility per managed property,
 * value and breakpoint, and one `revert-layer` utility per property and breakpoint, emitted
 * base, then md, then lg. Deterministic: a pure function of the vocabulary, the vocabulary
 * schema version and this class's VERSION, which together are the artifact's hash.
 */
final class StyleCompiler
{
    public const VERSION = 1;

    private const MEDIA = ['md' => 768, 'lg' => 1024];

    private const CHOICE_DECLARATIONS = [
        'alignment.text' => ['text-align' => ['start' => 'start', 'center' => 'center', 'end' => 'end']],
        'alignment.content' => [
            'justify-content' => ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end'],
        ],
        'alignment.self' => ['margin-inline' => ['start' => '0 auto', 'center' => 'auto', 'end' => 'auto 0']],
        'typography.weight' => [
            'font-weight' => ['regular' => '400', 'medium' => '500', 'semibold' => '600', 'bold' => '700'],
        ],
        'visibility' => ['display' => ['visible' => 'revert-layer', 'hidden' => 'none']],
        'border.width' => ['border-width' => ['none' => '0', 'thin' => '1px', 'thick' => '2px']],
        'border.style' => ['border-style' => ['solid' => 'solid', 'dashed' => 'dashed']],
    ];

    /** token properties: the CSS property that reads the token variable */
    private const TOKEN_PROPERTY = [
        'spacing.padding.top' => 'padding-top',
        'spacing.padding.right' => 'padding-right',
        'spacing.padding.bottom' => 'padding-bottom',
        'spacing.padding.left' => 'padding-left',
        'spacing.margin.top' => 'margin-top',
        'spacing.margin.bottom' => 'margin-bottom',
        'typography.size' => 'font-size',
        'shadow' => 'box-shadow',
        'radius' => 'border-radius',
        'colors.surface' => 'background-color',
        'colors.text' => 'color',
        'colors.border' => 'border-color',
    ];

    public static function hash(ThemeVocabulary $vocabulary): string
    {
        return substr(hash('sha256', json_encode([
            'vocabulary' => $vocabulary->values(),
            'schema' => Vocabulary::VERSION,
            'settings' => StyleSchema::VERSION,
            'compiler' => self::VERSION,
        ])), 0, 16);
    }

    public static function compile(ThemeVocabulary $vocabulary): string
    {
        $out = "@layer settings {\n";
        $out .= ":root {\n";
        foreach ($vocabulary->values() as $token => $value) {
            $out .= '  ' . self::variable($token) . ': ' . $value . ";\n";
        }
        $out .= "}\n";
        $out .= self::rules('base');
        foreach (self::MEDIA as $bp => $min) {
            $out .= "@media (min-width: {$min}px) {\n" . self::rules($bp) . "}\n";
        }
        return $out . "}\n";
    }

    /** `spacing.lg` → `--t-spacing-lg` */
    public static function variable(string $token): string
    {
        return '--t-' . str_replace('.', '-', $token);
    }

    private static function rules(string $bp): string
    {
        $out = '';
        foreach (StyleSchema::properties() as $path => $def) {
            if ($bp !== 'base' && !$def->responsive) {
                continue;
            }
            foreach (self::valuesFor($path, $def->tokenDomain, $def->choices) as $value) {
                $declarations = self::declarations($path, $value);
                $out .= ClassNames::selector(ClassNames::for($path, $value, $bp)) . ' { ' . $declarations . " }\n";
            }
            if ($def->accepts(ValueKind::Reset)) {
                $reset = implode(' ', array_map(
                    static fn (string $property): string => "{$property}: revert-layer;",
                    self::cssProperties($path),
                ));
                $out .= ClassNames::selector(ClassNames::reset($path, $bp)) . ' { ' . $reset . " }\n";
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function valuesFor(string $path, ?string $domain, ?array $choices): array
    {
        if ($domain !== null) {
            return array_map(static fn (string $name): string => "{$domain}.{$name}", Vocabulary::names($domain));
        }
        return $choices ?? [];
    }

    private static function declarations(string $path, string $value): string
    {
        if ($path === 'width') {
            return $value === 'width.full'
                ? 'max-width: none; width: 100%;'
                : 'max-width: var(' . self::variable($value) . ');';
        }
        if (isset(self::TOKEN_PROPERTY[$path])) {
            return self::TOKEN_PROPERTY[$path] . ': var(' . self::variable($value) . ');';
        }
        foreach (self::CHOICE_DECLARATIONS[$path] as $property => $map) {
            return "{$property}: {$map[$value]};";
        }
        throw new \LogicException("no declaration for {$path}");
    }

    /** @return list<string> the CSS properties a managed property writes */
    private static function cssProperties(string $path): array
    {
        if ($path === 'width') {
            return ['max-width', 'width'];
        }
        if (isset(self::TOKEN_PROPERTY[$path])) {
            return [self::TOKEN_PROPERTY[$path]];
        }
        return array_keys(self::CHOICE_DECLARATIONS[$path]);
    }
}
