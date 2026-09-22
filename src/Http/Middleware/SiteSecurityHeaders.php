<?php

declare(strict_types=1);

namespace Thallo\Render\Http\Middleware;

use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The baseline security headers of a rendered page. The framework applies them to the admin's
 * documents but not app-wide, so the site's own pages sent none: a site had them only if its web
 * server added them.
 *
 * - `X-Content-Type-Options: nosniff` and `Referrer-Policy: strict-origin-when-cross-origin`.
 * - `X-Frame-Options: SAMEORIGIN` — unless the response already decides framing with a
 *   `frame-ancestors` policy (the shop's Live Mirror does), and never on a preview or inside the
 *   Design view's canvas session, which the admin frames and may frame from another host.
 * - `Strict-Transport-Security` on a request that arrived over HTTPS (behind a proxy, only once
 *   TRUSTED_PROXIES lets the request know), without includeSubDomains: this site, not its
 *   neighbours.
 *
 * A header already present is never replaced: whatever is nearer the page — a controller, a
 * policy middleware, the operator's own configuration — wins. Placed before the page cache in a
 * route's chain so a cached page carries the headers too. HTTPS redirection stays the web
 * server's job: a redirect here would come after the request had already crossed in the clear.
 */
final class SiteSecurityHeaders implements RouteMiddleware
{
    public const CANVAS_COOKIE = 'thallo_preview_canvas';

    public function handle(Request $request, callable $next, ...$params): mixed
    {
        $response = $next($request);
        if (!$response instanceof Response) {
            return $response;
        }

        $headers = $response->headers;
        if (!$headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        // A preview is made to be framed by the admin; so is every page walked to in the canvas.
        $framedByAdmin = str_starts_with($request->getPathInfo(), '/_preview/')
            || $request->cookies->get(self::CANVAS_COOKIE) === '1';
        if (!$framedByAdmin && !$headers->has('X-Frame-Options') && !$this->decidesFraming($response)) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        if ($request->isSecure() && !$headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    /** An ENFORCED frame-ancestors policy decides framing; a report-only one decides nothing. */
    private function decidesFraming(Response $response): bool
    {
        $policy = strtolower((string) $response->headers->get('Content-Security-Policy', ''));
        return str_contains($policy, 'frame-ancestors');
    }
}
