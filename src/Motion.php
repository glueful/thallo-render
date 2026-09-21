<?php

declare(strict_types=1);

namespace Thallo\Render;

/**
 * The page's word that entrances will be revealed (the visual builder's motion settings).
 *
 * An entrance hides a block until it scrolls into view, so the hiding must be in force BEFORE the
 * block is parsed — or it paints, then vanishes, then animates in. The compiled stylesheet hides
 * only under `html[data-thallo-motion]`; this inline script sets that attribute. The renderer notes
 * while it renders that a block enters, and the finished page gets the flag in its head
 * (RenderContextExtension::finish()) with the deferred script that does the revealing — in the
 * head, never beside the block: a script among blocks is a sibling a theme's `:first-child` sees.
 *
 * It only ever hides what can be revealed again: not without IntersectionObserver, not for a
 * visitor who asked for reduced motion, and if the revealing script has not reported in
 * (`window.__thalloMotion`) within three seconds, the attribute comes off and everything shows.
 * Blocked by a strict Content-Security-Policy, it sets nothing and nothing is ever hidden; a site
 * that wants entrances under such a policy allows it by hash, as it does the colour-mode resolver.
 */
final class Motion
{
    /** The inline flag. Byte-stable literal — do NOT edit without updating FLAG_SHA256. */
    // phpcs:ignore Generic.Files.LineLength.TooLong -- hash-pinned literal; wrapping would break FLAG_SHA256
    public const FLAG_JS = "(function(d,w){try{if(!('IntersectionObserver' in w)||w.matchMedia('(prefers-reduced-motion: reduce)').matches){return;}var h=d.documentElement;h.setAttribute('data-thallo-motion','');w.setTimeout(function(){if(!w.__thalloMotion){h.removeAttribute('data-thallo-motion');}},3000);}catch(e){}})(document,window);";

    /** base64(sha256(FLAG_JS)) — what an operator adds to a strict CSP as 'sha256-...'. */
    public const FLAG_SHA256 = 'oITHwt56P1Z4Ld9JC9e+G5nYf5B76v5qE0R8X48Xz9E=';

    /** The revealing script, by its stable name (it redirects to the fingerprinted file). */
    public const SCRIPT = '/_thallo/runtime/block-motion.js';

    /** Exactly what a page with a block that enters gets in its head. */
    public static function tags(): string
    {
        return '<script>' . self::FLAG_JS . '</script><script defer src="' . self::SCRIPT . '"></script>';
    }
}
