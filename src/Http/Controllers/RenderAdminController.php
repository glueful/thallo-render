<?php

declare(strict_types=1);

namespace Thallo\Render\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Thallo\Render\Http\DTOs\AppearanceFingerprintData;
use Thallo\Render\Style\RequestFontSnapshot;
use Thallo\Render\ThemeAppearanceSource;

/**
 * What an open stage asks the renderer outside a render (block typeface plan Task 11). Read-only:
 * it mints nothing and touches no preview session.
 */
final class RenderAdminController
{
    public function __construct(
        private readonly ThemeAppearanceSource $appearance,
        private readonly ?RequestFontSnapshot $fonts = null,
    ) {
    }

    #[ApiOperation(
        summary: 'What a stage render\'s head depends on',
        description: 'The active theme, the saved appearance and the fonts stylesheet, as one opaque value — '
            . 'the `appearance_fingerprint` a stage render carries. An open stage compares the two to know '
            . 'that a change made elsewhere needs a reload. Computed as a stage render does, from saved '
            . 'appearance: a preview token\'s overrides never apply. Requires `content.edit`, '
            . '`content.manage` or `templates.manage`.',
        tags: ['Thallo Templates'],
    )]
    #[ApiResponse(200, schema: AppearanceFingerprintData::class, description: 'The fingerprint.')]
    public function appearanceFingerprint(): Response
    {
        // Read now, not from what this worker last memoised: the point is a change made elsewhere.
        $this->appearance->forget();
        $this->fonts?->refresh();
        $response = Response::success(['appearance_fingerprint' => $this->appearance->appearanceFingerprint()]);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
}
