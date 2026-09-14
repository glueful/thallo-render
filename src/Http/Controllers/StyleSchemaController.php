<?php

declare(strict_types=1);

namespace Thallo\Render\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Contracts\Style\ValueKind;
use Thallo\Contracts\Style\Vocabulary;
use Thallo\Render\Http\DTOs\StyleSchemaData;
use Thallo\Render\ThemeLocator;

/**
 * The style schema endpoint (visual builder spec §1.3, §3.4): the one runtime source the
 * inspector generates its controls from — the property table with kinds, responsiveness and
 * choices, the breakpoints, the advanced paths, and the active theme's vocabulary so a token
 * control can preview the theme's value. OpenAPI types stay compile-time only.
 */
final class StyleSchemaController
{
    public function __construct(private readonly ThemeLocator $theme)
    {
    }

    #[ApiOperation(
        summary: 'The style schema and the active theme vocabulary',
        description: 'The managed property table (paths, kinds, responsiveness, choices), the breakpoints, '
            . 'the advanced paths and the active theme\'s vocabulary values. Requires `content.manage`.',
        tags: ['Thallo Templates'],
    )]
    #[ApiResponse(200, schema: StyleSchemaData::class, description: 'The style schema.')]
    public function show(): Response
    {
        $properties = [];
        foreach (StyleSchema::properties() as $def) {
            $properties[] = [
                'path' => $def->path,
                'group' => $def->group,
                'kinds' => array_map(static fn (ValueKind $kind): string => $kind->value, $def->kinds),
                'responsive' => $def->responsive,
                'token_domain' => $def->tokenDomain,
                'choices' => $def->choices,
            ];
        }
        $domains = [];
        foreach (Vocabulary::domains() as $domain) {
            $domains[$domain] = Vocabulary::names($domain);
        }
        return Response::success([
            'version' => StyleSchema::VERSION,
            'breakpoints' => StyleSchema::BREAKPOINT_MIN_WIDTH,
            'properties' => $properties,
            'advanced' => array_keys(StyleSchema::advanced()),
            'vocabulary' => [
                'version' => Vocabulary::VERSION,
                'domains' => $domains,
                'values' => $this->theme->vocabulary()->values(),
            ],
        ], 'Style schema retrieved.');
    }
}
