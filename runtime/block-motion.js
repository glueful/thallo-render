/* Thallo block asset: motion (the visual builder's entrance settings). Loaded, deferred, only on a
   page where some block enters; the renderer emits it with the inline flag (Thallo\Render\Motion)
   immediately before the first such block. May execute MORE than once and possibly BEFORE
   ThalloRuntime exists — like the other block assets, the guard burns only after registration
   succeeds, and success immediately self-enhances.

   The compiled stylesheet hides an entrance only under html[data-thallo-motion] and only until
   the element carries data-thallo-entered. This script's whole job is to set that attribute when
   the element scrolls into view — and to take it off again, for an entrance set to replay, when
   it leaves. It reports in (window.__thalloMotion) so the inline flag's failsafe stands down. */
(function () {
  'use strict';
  if (window.__thalloBlockMotion) { return; }
  var RT = window.ThalloRuntime;
  if (!RT || typeof RT.register !== 'function') { return; } // retry on a later execution

  var root = document.documentElement;
  var usable = typeof IntersectionObserver === 'function'
    && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!usable) {
    // Nothing will be revealed by this script, so nothing may stay hidden.
    root.removeAttribute('data-thallo-motion');
    window.__thalloMotion = true;
    window.__thalloBlockMotion = true;
    return;
  }

  // An element enters when its top edge is a tenth of the viewport above the bottom: a tall
  // section would never reach a visibility ratio, so this is a line, not a fraction.
  var observer = new IntersectionObserver(function (entries) {
    for (var i = 0; i < entries.length; i++) {
      var entry = entries[i];
      var el = entry.target;
      if (entry.isIntersecting) {
        el.setAttribute('data-thallo-entered', '');
        if (!el.classList.contains('t-enterrepeat-always')) { observer.unobserve(el); }
      } else if (el.classList.contains('t-enterrepeat-always')) {
        el.removeAttribute('data-thallo-entered');
      }
    }
  }, { rootMargin: '0px 0px -10% 0px', threshold: 0 });

  RT.register('motion', {
    // Every entrance class starts `t-enter-`; `none` and a reset name no entrance.
    selector: '[class*="t-enter-"]',
    enhance: function (el) {
      if (el.classList.contains('t-enter-none') || el.classList.contains('t-enter-reset')) {
        return false;
      }
      observer.observe(el);
      return function () {
        observer.unobserve(el);
        el.removeAttribute('data-thallo-entered');
      };
    }
  });
  window.__thalloBlockMotion = true;
  window.__thalloMotion = true;
  RT.enhance(document);
})();
