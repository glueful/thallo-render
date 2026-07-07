<?php

declare(strict_types=1);

namespace Thallo\Render;

/**
 * Color-mode support (color-mode spec §3.1/§5). RESOLVER_JS is the ONE definition
 * of the no-flash resolver; the layout renders it verbatim so its sha256 (published
 * for CSP `script-src`) has a real source of truth instead of trusting byte stability
 * of the template. Never interpolate into RESOLVER_JS — one string, one hash.
 */
final class ColorMode
{
    /** The inline no-flash resolver. Byte-stable literal — do NOT edit without updating RESOLVER_SHA256. */
    public const RESOLVER_JS = "(function(){try{var k=localStorage.getItem('thallo.colorMode')||'system';var d=k==='dark'||(k!=='light'&&window.matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.dataset.theme=d?'dark':'light';}catch(e){document.documentElement.dataset.theme='light';}})();";

    /** base64(sha256(RESOLVER_JS)) — the value operators add to a strict CSP as 'sha256-...'. */
    public const RESOLVER_SHA256 = 'LPPpGD9ammrw92nJUwoMRPu1xnHk26P8c3tFKYUe8OE=';

    /** The exact <script> the layout emits (verbatim resolver, no attributes). */
    public static function scriptTag(): string
    {
        return '<script>' . self::RESOLVER_JS . '</script>';
    }
}
