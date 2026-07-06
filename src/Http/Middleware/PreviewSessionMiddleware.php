<?php

declare(strict_types=1);

namespace Thallo\Render\Http\Middleware;

use Thallo\Contracts\Delivery\PreviewSessionVerifier;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Session detection for preview sessions (preview-sessions spec §4) — deliberately
 * SEPARATE from RenderPageCache: session state is not cache state, and sessions must
 * survive cache_enabled=false. A lemma_preview cookie that VERIFIES becomes the
 * `lemma_preview_session` request attribute (the PreviewSession VO — one verification
 * per request, shared by the cache bypass, the controller chrome, and the overlay);
 * junk/expired cookies are silently ignored, so random cookies cannot cache-bust.
 */
final class PreviewSessionMiddleware implements RouteMiddleware
{
    public const ATTRIBUTE = 'lemma_preview_session';

    public function __construct(private readonly PreviewSessionVerifier $verifier)
    {
    }

    public function handle(Request $request, callable $next, ...$params): mixed
    {
        $token = (string) $request->cookies->get('lemma_preview', '');
        if ($token !== '') {
            $session = $this->verifier->verify($token);
            if ($session !== null) {
                $request->attributes->set(self::ATTRIBUTE, $session);
            }
        }
        return $next($request);
    }
}
