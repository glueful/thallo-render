/* Thallo theme runtime (theme-runtime spec §2) — package-owned behavioral JS for the
   default theme. Served fingerprinted at /_thallo/runtime/ (RuntimeAssetController);
   themes own presentation (CSS) only. Language floor: Baseline Widely Available, and
   this file must parse under Node >= 18 (the tests execute the served bytes).

   Core contract:
     ThalloRuntime.register(name, { enhance(component), selector, canvas })
       - name: unique; re-registering an existing name THROWS (silent replacement
         would hide behavior forks).
       - selector: what the module enhances; enhance() receives each matching
         component root exactly once (data-thallo-enhanced marker, per module).
       - canvas: 'skip' (default) — no-op when the canvas stage is present
         (.thallo-preview-block; injected DOM would break the canvas patch gate);
         'allow' — runs everywhere (color-mode only: it touches <html>, no block DOM).
     ThalloRuntime.enhance(root)
       - root is a SCAN BOUNDARY: the root itself (when it matches) plus matching
         descendants. Safe to call repeatedly and on inserted subtrees. */
(function () {
  'use strict';

  var modules = Object.create(null);
  var order = [];

  function isCanvas() {
    return !!document.querySelector('.thallo-preview-block');
  }

  function markerHas(elm, name) {
    var v = elm.getAttribute('data-thallo-enhanced');
    return v !== null && (' ' + v + ' ').indexOf(' ' + name + ' ') !== -1;
  }

  function mark(elm, name) {
    var v = elm.getAttribute('data-thallo-enhanced');
    elm.setAttribute('data-thallo-enhanced', v ? v + ' ' + name : name);
  }

  function componentsIn(root, selector) {
    var found = [];
    if (root.matches && root.matches(selector)) {
      found.push(root);
    }
    var all = root.querySelectorAll ? root.querySelectorAll(selector) : [];
    for (var i = 0; i < all.length; i++) {
      found.push(all[i]);
    }
    return found;
  }

  window.ThalloRuntime = {
    register: function (name, def) {
      if (modules[name]) {
        throw new Error('ThalloRuntime: module "' + name + '" is already registered');
      }
      modules[name] = {
        enhance: def.enhance,
        selector: def.selector,
        canvas: def.canvas === 'allow' ? 'allow' : 'skip'
      };
      order.push(name);
    },
    enhance: function (root) {
      var canvas = isCanvas();
      for (var i = 0; i < order.length; i++) {
        var name = order[i];
        var mod = modules[name];
        if (canvas && mod.canvas === 'skip') {
          continue;
        }
        var comps = componentsIn(root, mod.selector);
        for (var j = 0; j < comps.length; j++) {
          if (markerHas(comps[j], name)) {
            continue;
          }
          // A throwing module must not break the rest of the pass: the component stays
          // UNMARKED (its module made no completed enhancement) and every other
          // component and module still runs.
          try {
            mod.enhance(comps[j]);
            mark(comps[j], name);
          } catch (err) {
            if (window.console && console.error) {
              console.error('ThalloRuntime: module "' + name + '" failed', err);
            }
          }
        }
      }
    }
  };

  function boot() {
    window.ThalloRuntime.enhance(document.documentElement);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    // Deferred so module IIFEs appended below /* modules:start */ in this same file
    // have registered before the boot pass runs, regardless of position.
    Promise.resolve().then(boot);
  }
})();
/* modules:start */

/* Color-mode module registration — participates in the registry/duplicate-name
   contract. The behavior itself is the self-executing delegated IIFE between the
   markers below, kept byte-identical to the original blocks.js source so
   ColorModeRuntimeTest's marker extraction keeps evaluating the exact same bytes. */
window.ThalloRuntime.register('color-mode', {
  selector: 'html',
  canvas: 'allow',
  enhance: function () { /* no-op: this module is event-delegation based */ }
});
/* color-mode:start */
/* Color-mode runtime (color-mode spec §3.2). Its OWN IIFE — deliberately not
   gated by the carousel canvas no-op above, since it injects no block DOM (only
   sets data-theme on <html>) and so never diverges wrapper HTML from fetched.

   HARD GATE: does nothing unless the server stamped the marker
   html[data-color-mode-enabled="true"]. Feature off → inert, even if
   localStorage still holds 'dark' from a previous run. The inline head resolver
   (ColorMode::RESOLVER_JS) already set data-theme pre-paint for no flash; this
   runtime keeps it synced with the OS while in 'system' mode and drives the
   toggle controls. */
(function () {
  'use strict';
  var root = document.documentElement;
  if (root.dataset.colorModeEnabled !== 'true') return; // feature off → inert

  var KEY = 'thallo.colorMode';
  var EVENT = 'thallo:color-mode-change';
  var mql = window.matchMedia('(prefers-color-scheme: dark)');

  function stored() {
    try { return localStorage.getItem(KEY) || 'system'; } catch (e) { return 'system'; }
  }
  function resolve(mode) {
    return mode === 'dark' || (mode !== 'light' && mql.matches) ? 'dark' : 'light';
  }
  function apply(mode) {
    root.dataset.theme = resolve(mode);
  }
  // Reflect the STORED preference (light/system/dark — not the resolved theme)
  // onto every toggle control so the segmented switch shows the active option.
  function reflect() {
    var mode = stored();
    var opts = document.querySelectorAll('[data-color-mode-set]');
    Array.prototype.forEach.call(opts, function (el) {
      el.setAttribute('aria-checked', el.getAttribute('data-color-mode-set') === mode ? 'true' : 'false');
    });
  }
  function setMode(mode) {
    if (mode !== 'light' && mode !== 'dark' && mode !== 'system') return; // ignore junk
    try { localStorage.setItem(KEY, mode); } catch (e) {}
    apply(mode);
    reflect();
    root.dispatchEvent(new CustomEvent(EVENT, {
      detail: { mode: mode, resolved: resolve(mode) }
    }));
  }

  // Follow the OS ONLY while in 'system' mode; an explicit light/dark choice
  // pins data-theme and ignores OS flips.
  mql.addEventListener('change', function () {
    if (stored() === 'system') apply('system');
  });

  // Toggle controls (the color_mode block): a click on any element carrying
  // data-color-mode-set="light|dark|system" sets that mode. The markup lives in
  // the block template; the behaviour lives here (color-mode spec §3.2 pin).
  document.addEventListener('click', function (e) {
    var el = e.target && e.target.closest ? e.target.closest('[data-color-mode-set]') : null;
    if (!el) return;
    e.preventDefault();
    setMode(el.getAttribute('data-color-mode-set'));
  });

  reflect(); // initial pressed state for any toggle present on load

  // Public API — allows programmatic control and lets embedders re-sync.
  window.thalloColorMode = {
    get: stored,
    set: setMode,
    resolved: function () { return resolve(stored()); },
    reflect: reflect
  };
})();
/* color-mode:end */

/* form-block:start — progressive enhancement for [data-thallo-form] (form-block spec §6
   + theme-runtime spec §6). No-JS baseline is a normal POST to /_forms/submit (PRG); with
   JS we intercept, POST via fetch, and render the result inline with focus + live
   announcement. */
window.ThalloRuntime.register('forms', {
  selector: 'form[data-thallo-form]',
  enhance: function (form) {
    var box = form.querySelector('.thallo-block-form__result');
    var btn = form.querySelector('.thallo-block-form__submit');
    if (box) {
      box.setAttribute('role', 'status');
      box.setAttribute('aria-live', 'polite');
      box.setAttribute('tabindex', '-1');
    }
    function setResult(message, ok) {
      if (!box) { return; }
      box.textContent = message;
      box.classList.remove('thallo-block-form__result--error', 'thallo-block-form__result--ok');
      box.classList.add(ok ? 'thallo-block-form__result--ok' : 'thallo-block-form__result--error');
      if (!ok) { box.focus(); }
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (btn) { btn.disabled = true; }
      form.setAttribute('aria-busy', 'true');
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' }
      }).then(function (res) {
        return res.json().catch(function () { return {}; });
      }).then(function (json) {
        if (json && json.ok) {
          form.reset();
          setResult(json.message || 'Thanks — your message has been sent.', true);
        } else {
          setResult((json && json.error) || 'Please check your entries and try again.', false);
        }
      }).catch(function () {
        setResult('Something went wrong. Please try again.', false);
      }).then(function () {
        if (btn) { btn.disabled = false; }
        form.removeAttribute('aria-busy');
      });
    });
  }
});
/* form-block:end */

/* carousel:start — carousel enhancement (block-library spec §2 + theme-runtime spec §5).
   The scroll-snap base works with no JS at all; this module adds arrows / dots /
   autoplay where the block asked for them, plus the §5 accessibility corrections:
     - a visible pause/play control whenever autoplay runs (aria-pressed="true" means
       the USER paused rotation; label and state switch together in syncPause() so
       their meaning can never invert);
     - automatic pause while the carousel is offscreen (IntersectionObserver) or the
       tab is hidden (visibilitychange) — temporary reasons that resume only when
       every gate is clear;
     - a visually-hidden 'Slide N of M' status region: aria-live="off" during
       automatic rotation (no interruption every five seconds), switched to
       "polite" after any user pause/interaction and for user-initiated navigation.
   userPaused is STICKY: automatic recovery (re-intersecting, tab visible again)
   never clears it; only the explicit Play action does — and Play itself re-checks
   every automatic gate in startAuto() before rotating. prefers-reduced-motion
   disables autoplay entirely: no interval ever, no pause button.
   Canvas: 'skip' (default) — injected controls would diverge wrapper HTML from
   fetched HTML and break the canvas patch gate. */
(function () {
  'use strict';

  function button(className, label, text) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = className;
    b.setAttribute('aria-label', label);
    b.textContent = text;
    return b;
  }

  function throttle(fn, ms) {
    var t = 0;
    return function () {
      var now = Date.now();
      if (now - t >= ms) { t = now; fn(); }
    };
  }

  function enhanceCarousel(root) {
    var viewport = root.querySelector('.thallo-block-carousel__viewport');
    var track = root.querySelector('.thallo-block-carousel__track');
    if (!viewport || !track) { return; }
    var slides = Array.prototype.filter.call(track.children, function (el) {
      return el.nodeType === 1;
    });
    if (slides.length < 2) { return; }

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var timer = null;
    var userPaused = false; // sticky: only the explicit Play control clears it
    var autoOffscreen = false; // automatic gate: carousel out of the viewport
    var autoHidden = false; // automatic gate: tab hidden
    var live = null; // 'Slide N of M' status region
    var pauseBtn = null;

    function slideStart(i) {
      return slides[i] ? slides[i].offsetLeft - track.offsetLeft : 0;
    }
    function currentIndex() {
      var x = viewport.scrollLeft;
      var best = 0;
      var bestDist = Infinity;
      slides.forEach(function (s, i) {
        var d = Math.abs(slideStart(i) - x);
        if (d < bestDist) { bestDist = d; best = i; }
      });
      return best;
    }
    function norm(i) {
      var n = slides.length;
      return ((i % n) + n) % n;
    }
    function goTo(i) {
      viewport.scrollTo({ left: slideStart(norm(i)), behavior: 'smooth' });
    }
    function announce(i) {
      if (!live) { return; }
      live.textContent = 'Slide ' + (i + 1) + ' of ' + slides.length;
    }
    function politeAfterUserAction() {
      if (live) { live.setAttribute('aria-live', 'polite'); }
    }
    function syncPause() {
      if (!pauseBtn) { return; }
      pauseBtn.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
      pauseBtn.setAttribute('aria-label', userPaused ? 'Play slides' : 'Pause slides');
      pauseBtn.textContent = userPaused ? '▶' : '⏸';
    }
    function startAuto() {
      // Every automatic gate re-checked on every start attempt (incl. explicit Play).
      if (userPaused || autoOffscreen || autoHidden || reducedMotion || timer) { return; }
      timer = setInterval(function () {
        var n = currentIndex() + 1;
        goTo(n);
        announce(norm(n)); // aria-live=off while automatic: no interruption
      }, 5000);
      syncPause();
    }
    function stopAuto() {
      if (timer) { clearInterval(timer); timer = null; }
      syncPause();
    }
    function userInteracted() {
      userPaused = true; // sticky until explicit Play
      stopAuto();
      politeAfterUserAction();
    }

    if (root.dataset.arrows === '1') {
      var prev = button('thallo-block-carousel__prev', 'Previous slide', '‹');
      var next = button('thallo-block-carousel__next', 'Next slide', '›');
      prev.addEventListener('click', function () {
        var n = currentIndex() - 1;
        userInteracted();
        goTo(n);
        announce(norm(n));
      });
      next.addEventListener('click', function () {
        var n = currentIndex() + 1;
        userInteracted();
        goTo(n);
        announce(norm(n));
      });
      root.appendChild(prev);
      root.appendChild(next);
    }

    var dots = [];
    if (root.dataset.dots === '1') {
      var wrap = document.createElement('div');
      wrap.className = 'thallo-block-carousel__dots';
      slides.forEach(function (_, i) {
        var dot = button('thallo-block-carousel__dot', 'Go to slide ' + (i + 1), '');
        dot.addEventListener('click', function () {
          userInteracted();
          goTo(i);
          announce(i);
        });
        dots.push(dot);
        wrap.appendChild(dot);
      });
      root.appendChild(wrap);
      var syncDots = function () {
        var active = currentIndex();
        dots.forEach(function (d, i) {
          d.setAttribute('aria-current', i === active ? 'true' : 'false');
        });
      };
      viewport.addEventListener('scroll', throttle(syncDots, 100), { passive: true });
      syncDots();
    }

    if (root.dataset.autoplay === '1' && !reducedMotion) {
      live = document.createElement('span');
      live.className = 'thallo-block-carousel__status';
      live.setAttribute('aria-live', 'off'); // silent while rotation is automatic
      root.appendChild(live);
      announce(currentIndex());

      pauseBtn = button('thallo-block-carousel__pause', 'Pause slides', '⏸');
      pauseBtn.addEventListener('click', function () {
        userPaused = !userPaused;
        if (userPaused) { stopAuto(); } else { startAuto(); }
        syncPause(); // startAuto may have declined (gates); label/state stay in sync
        politeAfterUserAction();
      });
      root.appendChild(pauseBtn);
      syncPause();

      // Any direct interaction with the slides pauses rotation until explicit Play.
      ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (ev) {
        viewport.addEventListener(ev, userInteracted, { passive: true });
      });

      if (typeof IntersectionObserver === 'function') {
        new IntersectionObserver(function (entries) {
          for (var i = 0; i < entries.length; i++) {
            autoOffscreen = !entries[i].isIntersecting;
          }
          if (autoOffscreen) { stopAuto(); } else { startAuto(); }
        }).observe(root);
      }

      document.addEventListener('visibilitychange', function () {
        autoHidden = !!document.hidden;
        if (autoHidden) { stopAuto(); } else { startAuto(); }
      });

      autoHidden = !!document.hidden;
      startAuto();
    }
  }

  window.ThalloRuntime.register('carousel', {
    selector: '.thallo-block-carousel',
    enhance: enhanceCarousel
  });
})();
/* carousel:end */

/* navigation:start — navigation enhancement (theme-runtime spec §3.2). The
   <details>/<summary> floor works with no JS at all: every parent is a native
   disclosure, and details name= exclusivity is a progressive extra in the server
   markup only. This module layers on:
     - the .thallo-block-navigation--js root handoff (suppresses the raw
       closed-details hover reveal in navigation.css so hover intent governs);
     - one-open-sibling among the parent __details, enforced HERE on toggle
       events — never by relying on details name=;
     - keyboard surface: Enter/Space toggle natively on <summary> (no handler
       needed); ArrowDown on a summary opens the submenu and focuses its first
       link; Escape inside an open submenu closes it and refocuses its summary;
     - hover intent on --reveal-hover roots at desktop width only: mouseenter
       opens immediately (cancelling any pending close), mouseleave closes after
       a 180ms delay;
     - outside-click closing any open submenu, and link clicks closing the outer
       mobile drawer on mobile viewports only;
     - the outer __mobile details state machine (spec §2.1 + §3.2): the desktop
       CSS re-exposure of the closed-details list rides ::details-content, which
       is newer than the Baseline floor — so the runtime guarantees the list is
       visible on desktop by keeping the outer details OPEN there (its hamburger
       chrome is display:none above 48rem anyway) and closes it when crossing to
       mobile width;
     - open animation via element.animate only, skipped under
       prefers-reduced-motion.
   Canvas: 'skip' (default) — live pages only. */
(function () {
  'use strict';

  // 48rem: the navigation component's named v1 breakpoint (theme-runtime spec §3.2)
  var BREAKPOINT = '(max-width: 48rem)';

  window.ThalloRuntime.register('navigation', {
    selector: '[data-thallo-enhance="navigation"]',
    enhance: function (mobile) {
      var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      var mq = window.matchMedia(BREAKPOINT);
      // The layout's fallback nav (site-nav__mobile) has no block root: the
      // details itself is the closest thing to one, and it has no __details
      // parents, so the submenu wiring below is a harmless no-op there.
      var root = (mobile.closest && mobile.closest('.thallo-block-navigation')) || mobile;
      if (root.classList) { root.classList.add('thallo-block-navigation--js'); }
      var revealHover = !!(root.classList &&
        root.classList.contains('thallo-block-navigation--reveal-hover'));
      var parents = mobile.querySelectorAll('.thallo-block-navigation__details');

      function closeOthers(except) {
        for (var i = 0; i < parents.length; i++) {
          var d = parents[i];
          if (d === except) { continue; }
          if (except && d.contains && d.contains(except)) { continue; } // ancestors stay open
          if (d.open) { d.open = false; }
        }
      }
      function animateOpen(panel) {
        if (reduced || !panel || !panel.animate) { return; }
        panel.animate(
          [{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'none' }],
          { duration: 120, easing: 'ease-out' }
        );
      }

      for (var i = 0; i < parents.length; i++) {
        (function (d) {
          var summary = d.querySelector('[data-nav-toggle]');
          var closeTimer = null;

          d.addEventListener('toggle', function () {
            if (d.open) {
              closeOthers(d);
              animateOpen(d.querySelector('[data-nav-panel]'));
            }
          });

          // Escape (bubbling from anywhere inside the open submenu) closes it
          // and restores focus to the toggle.
          d.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && d.open) {
              d.open = false;
              if (summary && summary.focus) { summary.focus(); }
            }
          });

          if (summary) {
            // ArrowDown opens and moves focus into the panel. Enter/Space need
            // no handler: <summary> toggles natively.
            summary.addEventListener('keydown', function (e) {
              if (e.key === 'ArrowDown') {
                if (e.preventDefault) { e.preventDefault(); }
                d.open = true;
                var f = d.querySelector(
                  '.thallo-block-navigation__sublink, .thallo-block-navigation__col-title'
                );
                if (f && f.focus) { f.focus(); }
              }
            });
          }

          // Hover intent (reveal-hover roots only). The viewport check lives in
          // the handlers so crossing the breakpoint needs no re-binding: below
          // 48rem hover is inert and the in-flow tap disclosure governs.
          if (revealHover && d.parentNode && d.parentNode.addEventListener) {
            d.parentNode.addEventListener('mouseenter', function () {
              if (mq.matches) { return; } // hover reveal is a desktop affordance
              if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
              d.open = true;
            });
            d.parentNode.addEventListener('mouseleave', function () {
              if (mq.matches) { return; }
              closeTimer = setTimeout(function () { closeTimer = null; d.open = false; }, 180);
            });
          }
        })(parents[i]);
      }

      // Outside-click closes any open submenu; a click on a link inside the menu
      // closes the mobile drawer — on mobile viewports only (on desktop the outer
      // details must stay open: it is what keeps the list visible).
      document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !(mobile.contains && mobile.contains(t))) {
          closeOthers(null);
          return;
        }
        if (t.closest && t.closest('a[href]') && mq.matches) {
          mobile.open = false;
        }
      });

      // Outer-details state machine: OPEN on desktop, closed when crossing to
      // mobile (the drawer chrome only exists below 48rem).
      mq.addEventListener('change', function (e) {
        mobile.open = !e.matches;
      });
      if (!mq.matches) { mobile.open = true; }
    }
  });
})();
/* navigation:end */
