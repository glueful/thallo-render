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
    // data-loop="1" keeps rotating instead of settling after one cycle. The
    // offscreen/hidden pauses still apply, and hovering pauses (below) so the
    // current word can be read; reduced motion never reaches this code.
    var looping = root.getAttribute('data-loop') === '1';
    // Seconds per word (data-interval, template-clamped 0.5–10); the 2.5s
    // default replaced a hardcoded 1s that read as too fast (2026-08 polish).
    var stepMs = 1000 * (parseFloat(root.getAttribute('data-interval')) || 2.5);

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
        index = (index + 1) % words.length;
        setActive(index);
        if (!looping && index >= words.length - 1) { done = true; stop(); } // ONE cycle, settle on last
      }, stepMs);
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

      if (looping) {
        // Hover pauses an endless rotation so the current word can be read.
        var onEnter = function () { stop(); };
        var onLeave = function () { maybeRun(); };
        undo.push(function () {
          root.removeEventListener('pointerenter', onEnter);
          root.removeEventListener('pointerleave', onLeave);
        });
        root.addEventListener('pointerenter', onEnter);
        root.addEventListener('pointerleave', onLeave);
      }

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
    // register() threw (name already taken — e.g. guard was externally cleared while
    // the registry kept the module): stay unguarded, mutate nothing; the winning
    // execution's registration stands.
    return;
  }
  window.__thalloBlockAnimatedText = true;
  RT.enhance(document.documentElement); // late-registration correctness authority
})();
