/* Thallo block asset: animated_text (modern-blocks spec §3). Loaded lazily via
   block_script('animated-text'); may execute MORE than once (fragment renders emit
   duplicate tags) and possibly BEFORE ThalloRuntime exists — the guard burns only
   after registration succeeds, and success immediately self-enhances so late
   registration (after the runtime's boot pass) still enhances existing blocks. */
(function () {
  'use strict';
  if (window.__thalloBlockAnimatedText) { return; }
  var RT = window.ThalloRuntime;
  if (!RT || typeof RT.register !== 'function') { return; } // retry on a later execution

  function enhance(root) {
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced) { return false; } // static floor is already correct — nothing to do
    if (typeof IntersectionObserver !== 'function') { return false; }

    var words = [];
    var stack = root.querySelector('.thallo-block-animated_text__rotate');
    if (stack) {
      var all = stack.querySelectorAll('.thallo-block-animated_text__word');
      for (var i = 0; i < all.length; i++) { words.push(all[i]); }
    }

    var undo = [];
    var timer = null;
    var inView = false;
    var index = 0;
    var done = words.length < 2; // nothing to rotate

    function setActive(n) {
      for (var k = 0; k < words.length; k++) {
        if (k === n) { words[k].classList.add('thallo-block-animated_text__word--active'); }
        else { words[k].classList.remove('thallo-block-animated_text__word--active'); }
      }
    }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function maybeRun() {
      if (done || !inView || document.hidden || timer) { return; }
      timer = setInterval(function () {
        index++;
        setActive(index);
        if (index >= words.length - 1) { done = true; stop(); } // ONE cycle, settle on last
      }, 1000);
    }

    try {
      var io = new IntersectionObserver(function (entries) {
        for (var e = 0; e < entries.length; e++) { inView = entries[e].isIntersecting; }
        if (inView && !root.classList.contains('thallo-block-animated_text--in-view')) {
          root.classList.add('thallo-block-animated_text--in-view'); // reveal, once
        }
        if (inView) { maybeRun(); } else { stop(); }
      });
      // Register rollback BEFORE every side effect. Removal/disconnect before an
      // add/observe is harmless; recording it afterwards leaves a partial-mutation
      // window if a DOM implementation mutates and then throws.
      undo.push(function () { io.disconnect(); });
      io.observe(root);

      var onVis = function () { if (document.hidden) { stop(); } else { maybeRun(); } };
      undo.push(function () { document.removeEventListener('visibilitychange', onVis); });
      document.addEventListener('visibilitychange', onVis);

      // Prepared LAST (fail-safe handoff, spec §3): reveal CSS engages only now.
      undo.push(function () {
        root.classList.remove('thallo-block-animated_text--prepared');
        root.classList.remove('thallo-block-animated_text--in-view');
      });
      root.classList.add('thallo-block-animated_text--prepared');
    } catch (err) {
      stop();
      for (var u = undo.length - 1; u >= 0; u--) { try { undo[u](); } catch (e2) {} }
      throw err; // containment leaves the block unmarked; static floor intact
    }

    return function () {
      stop();
      setActive(0);
      for (var u2 = undo.length - 1; u2 >= 0; u2--) {
        try { undo[u2](); } catch (cleanupErr) {}
      }
    };
  }

  try {
    RT.register('animated-text', { selector: '.thallo-block-animated_text', enhance: enhance });
  } catch (err) {
    return; // duplicate registration (another execution won) — its guard is set
  }
  window.__thalloBlockAnimatedText = true;
  RT.enhance(document.documentElement); // late-registration correctness authority
})();
