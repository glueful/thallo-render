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

  function unmark(elm, name) {
    var v = elm.getAttribute('data-thallo-enhanced');
    if (v === null) { return; }
    var parts = v.split(' ').filter(function (t) { return t && t !== name; });
    if (parts.length) { elm.setAttribute('data-thallo-enhanced', parts.join(' ')); }
    else { elm.removeAttribute('data-thallo-enhanced'); }
  }

  // Cleanup storage keyed by (component, module) — a per-element single slot would
  // let one module's cleanup overwrite another's (spec §1).
  var cleanups = typeof WeakMap === 'function' ? new WeakMap() : null;
  function storeCleanup(comp, name, fn) {
    if (!cleanups || typeof fn !== 'function') { return; }
    var perModule = cleanups.get(comp);
    if (!perModule) { perModule = new Map(); cleanups.set(comp, perModule); }
    perModule.set(name, fn);
  }
  function takeCleanup(comp, name) {
    var perModule = cleanups ? cleanups.get(comp) : null;
    if (!perModule || !perModule.has(name)) { return null; }
    var fn = perModule.get(name);
    perModule.delete(name);
    if (perModule.size === 0) { cleanups.delete(comp); }
    return fn;
  }

  /* Private per-component pipeline (spec §1): canvas policy -> marker check ->
     try/catch -> mark. The outcome vocabulary is internal — consumed by
     registerElement (elements section), ignored by the scan loop. */
  function runComponent(comp, name) {
    var mod = modules[name];
    if (isCanvas() && mod.canvas === 'skip') { return 'canvas-skipped'; }
    if (markerHas(comp, name)) { return 'already-enhanced'; }
    try {
      var result = mod.enhance(comp);
      if (result === false) { return 'structural-noop'; } // never marked
      if (typeof result === 'function') { storeCleanup(comp, name, result); }
      mark(comp, name);
      return 'enhanced';
    } catch (err) {
      if (window.console && console.error) {
        console.error('ThalloRuntime: module "' + name + '" failed', err);
      }
      return 'failed';
    }
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
        if (canvas && modules[name].canvas === 'skip') { continue; }
        var comps = componentsIn(root, modules[name].selector);
        for (var j = 0; j < comps.length; j++) {
          runComponent(comps[j], name);
        }
      }
    }
  };

  /* Element bridge (spec §1): the ONE public path from custom elements into the
     private pipeline. Defines only what it is told; Task 6 registers the three
     module-backed v1 tags. Guarded: without customElements the elements are simply
     absent and the class-based path is untouched. */
  var elementRecords = typeof WeakMap === 'function' ? new WeakMap() : null;
  function abandonElementRecord(host, rec) {
    rec.pending = false;
    if (rec.undo) {
      try { rec.undo(); } catch (err) { /* rollback must not strand the record */ }
    }
    rec.undo = null;
    rec.target = null;
    if (elementRecords.get(host) === rec) { elementRecords.delete(host); }
  }
  function defineElement(tag, moduleName, opts) {
    var resolveTarget = (opts && opts.resolveTarget) || function (host) { return host; };
    var projectOptions = (opts && opts.projectOptions) || null;

    class ThalloElement extends HTMLElement {
      connectedCallback() {
        var host = this;
        var rec = { pending: true, undo: null, target: null };
        elementRecords.set(host, rec);
        // One-microtask deferral (spec §1): synchronously-constructed children are
        // complete; asynchronously-populated elements must be built before insertion.
        Promise.resolve().then(function () {
          if (!rec.pending || elementRecords.get(host) !== rec) { return; }
          rec.pending = false;
          if (host.isConnected === false) { abandonElementRecord(host, rec); return; }
          var mod = modules[moduleName];
          if (!mod) { abandonElementRecord(host, rec); return; }
          // Canvas gate FIRST — before ANY mutation, including projection.
          if (isCanvas() && mod.canvas === 'skip') {
            abandonElementRecord(host, rec); return;
          }
          var target;
          try {
            target = resolveTarget(host);
            if (!target) { abandonElementRecord(host, rec); return; }
            rec.target = target;
            if (projectOptions) { rec.undo = projectOptions(host, target) || null; }
          } catch (err) {
            // resolveTarget/projectOptions are caller-supplied adapters — a throw from
            // either must not become an unhandled rejection nor strand the record.
            if (window.console && console.error) {
              console.error('ThalloRuntime: element "' + tag + '" adapter failed', err);
            }
            abandonElementRecord(host, rec);
            return;
          }
          var outcome = runComponent(target, moduleName);
          if (outcome === 'enhanced' || outcome === 'already-enhanced') { return; }
          // Transactional rollback: structural-noop / failed / canvas-skipped
          // (including a canvas that appeared during projection) all leave NO record.
          abandonElementRecord(host, rec);
        });
      }
      disconnectedCallback() {
        var rec = elementRecords.get(this);
        if (!rec) { return; }
        elementRecords.delete(this);
        if (rec.pending) { // cancel pending connection work
          abandonElementRecord(this, rec);
          return;
        }
        if (rec.target) {
          var fn = takeCleanup(rec.target, moduleName);
          if (fn) { try { fn(); } catch (err) { /* teardown must not break disconnect */ } }
          unmark(rec.target, moduleName); // ONLY this module's token
        }
        if (rec.undo) {
          try { rec.undo(); } catch (err) { /* teardown must not break disconnect */ }
        }
      }
    }
    customElements.define(tag, ThalloElement);
  }

  // Attach conditionally so the whole feature is absent where custom elements are
  // (Node harness without a stub, legacy browsers) — existing tests keep passing.
  if (typeof customElements !== 'undefined' && customElements &&
      typeof customElements.define === 'function' && typeof HTMLElement === 'function' &&
      elementRecords) {
    window.ThalloRuntime.registerElement = defineElement;
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
    try { return localStorage.getItem(KEY) || 'system'; } catch { return 'system'; }
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
    try { localStorage.setItem(KEY, mode); } catch {}
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
    if (!viewport || !track) { return false; }
    var slides = Array.prototype.filter.call(track.children, function (el) {
      return el.nodeType === 1;
    });
    if (slides.length < 2) { return false; }

    // Teardown accounting: every injected node / listener the module adds is
    // captured here, undone in reverse on the returned cleanup (spec §1).
    var undo = [];
    function addNode(parent, node) {
      parent.appendChild(node);
      undo.push(function () {
        if (node.parentNode) { node.parentNode.removeChild(node); }
      });
    }
    function listen(targetEl, type, fn, opts) {
      targetEl.addEventListener(type, fn, opts);
      undo.push(function () { targetEl.removeEventListener(type, fn, opts); });
    }

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var timer = null;
    var userPaused = false; // sticky: only the explicit Play control clears it
    var autoOffscreen = false; // automatic gate: carousel out of the viewport
    var autoHidden = false; // automatic gate: tab hidden
    var live = null; // 'Slide N of M' status region
    var pauseBtn = null;
    var io = null;

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
      addNode(root, prev);
      addNode(root, next);
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
      addNode(root, wrap);
      var syncDots = function () {
        var active = currentIndex();
        dots.forEach(function (d, i) {
          d.setAttribute('aria-current', i === active ? 'true' : 'false');
        });
      };
      listen(viewport, 'scroll', throttle(syncDots, 100), { passive: true });
      syncDots();
    }

    if (root.dataset.autoplay === '1' && !reducedMotion) {
      live = document.createElement('span');
      live.className = 'thallo-block-carousel__status';
      live.setAttribute('aria-live', 'off'); // silent while rotation is automatic
      addNode(root, live);
      announce(currentIndex());

      pauseBtn = button('thallo-block-carousel__pause', 'Pause slides', '⏸');
      pauseBtn.addEventListener('click', function () {
        userPaused = !userPaused;
        if (userPaused) { stopAuto(); } else { startAuto(); }
        syncPause(); // startAuto may have declined (gates); label/state stay in sync
        politeAfterUserAction();
      });
      addNode(root, pauseBtn);
      syncPause();

      // Any direct interaction with the slides pauses rotation until explicit Play.
      ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (ev) {
        listen(viewport, ev, userInteracted, { passive: true });
      });

      if (typeof IntersectionObserver === 'function') {
        io = new IntersectionObserver(function (entries) {
          for (var i = 0; i < entries.length; i++) {
            autoOffscreen = !entries[i].isIntersecting;
          }
          if (autoOffscreen) { stopAuto(); } else { startAuto(); }
        });
        io.observe(root);
        undo.push(function () { if (io) { io.disconnect(); } });
      }

      var onVisibilityChange = function () {
        autoHidden = !!document.hidden;
        if (autoHidden) { stopAuto(); } else { startAuto(); }
      };
      listen(document, 'visibilitychange', onVisibilityChange);

      autoHidden = !!document.hidden;
      startAuto();
      undo.push(function () { stopAuto(); });
    }

    return function () {
      for (var i = undo.length - 1; i >= 0; i--) { undo[i](); }
    };
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

/* tabs:start — tabs enhancement (theme-runtime spec §4). The radio floor works with
   no JS at all and deliberately claims NO ARIA (radios+labels are not tabs
   semantics); this module layers the REAL pattern on top: tablist/tab/tabpanel
   roles with stable derived ids, roving tabindex, automatic activation
   (ArrowLeft/ArrowRight with wrap, Home/End — focus and select together), label
   clicks driving selection, and panel visibility via [hidden]. Two hard rules:
     - RADIO SYNC: the floor's enumerated checked-pairing selector (0,6,0)
       outranks the enhanced __panel[hidden] rule (0,4,0), so a hidden panel
       whose radio stayed checked would remain visible. select() therefore keeps
       radio.checked aligned with the active tab on EVERY change and dispatches
       'change' on the newly-checked radio for anything listening to the floor.
     - FAIL-SAFE: enhancement runs as three ordered phases (ARIA/tabindex/id
       attributes -> event listeners -> hide radios LAST) behind a full undo
       log. Any throw replays the log in reverse (listeners detached, attributes
       restored) and RETHROWS, so the core's containment leaves the component
       unmarked, the enhanced-mode CSS never engages, and the honest radio floor
       stays byte- and behavior-intact. The core stamps data-thallo-enhanced
       itself, immediately after enhance returns — never this module.
   The module itself is UNBOUNDED (the 12-item cap is a Thallo authoring rule,
   enforced server-side; custom markup with more items enhances fully).
   Canvas: 'skip' (default) — live pages only. */
(function () {
  'use strict';

  // Direct-children lookup: keeps a tabs block nested inside another tabs
  // block's panel from leaking its radios/labels/panels into the outer one.
  function childrenWithClass(parent, cls) {
    var out = [];
    var kids = (parent && parent.children) || [];
    for (var i = 0; i < kids.length; i++) {
      if (kids[i].classList && kids[i].classList.contains(cls)) { out.push(kids[i]); }
    }
    return out;
  }

  function enhanceTabs(root) {
    var radios = childrenWithClass(root, 'thallo-block-tabs__radio');
    var list = childrenWithClass(root, 'thallo-block-tabs__list')[0] || null;
    var panelsBox = childrenWithClass(root, 'thallo-block-tabs__panels')[0] || null;
    var labels = list ? childrenWithClass(list, 'thallo-block-tabs__label') : [];
    var panels = panelsBox ? childrenWithClass(panelsBox, 'thallo-block-tabs__panel') : [];

    if (radios.length === 0 && labels.length === 0 && panels.length === 0) {
      return; // empty block: nothing to enhance, marking it is harmless
    }
    if (radios.length === 0 || labels.length !== radios.length || panels.length !== radios.length) {
      // Unpairable structure: throw so the component stays UNMARKED and the
      // enhanced-mode CSS (which trusts [hidden] we never got to set) stays off.
      throw new Error('tabs: radios/labels/panels do not pair; leaving the radio floor as-is');
    }

    var undo = []; // every mutation in order; replayed in reverse on any throw
    function setAttr(elm, name, value) {
      undo.push({ kind: 'attr', el: elm, attr: name, prior: elm.getAttribute(name) });
      elm.setAttribute(name, value);
    }
    function listen(elm, type, handler) {
      undo.push({ kind: 'listener', el: elm, type: type, handler: handler });
      elm.addEventListener(type, handler);
    }
    function rollback() {
      for (var i = undo.length - 1; i >= 0; i--) {
        var u = undo[i];
        if (u.kind === 'listener') {
          u.el.removeEventListener(u.type, u.handler);
        } else if (u.prior === null) {
          u.el.removeAttribute(u.attr);
        } else {
          u.el.setAttribute(u.attr, u.prior);
        }
      }
    }

    // Preselected radio (checked server-side, any index) seeds the active tab.
    var current = 0;
    var i;
    for (i = 0; i < radios.length; i++) {
      if (radios[i].checked) { current = i; }
    }

    function select(idx) {
      if (idx === current) { return; }
      for (var k = 0; k < labels.length; k++) {
        labels[k].setAttribute('aria-selected', k === idx ? 'true' : 'false');
        labels[k].setAttribute('tabindex', k === idx ? '0' : '-1');
      }
      // Radio sync is load-bearing: the floor's checked-pairing CSS outranks
      // the enhanced [hidden] rule, so the old radio must never stay checked.
      for (k = 0; k < radios.length; k++) {
        radios[k].checked = k === idx;
      }
      if (radios[idx].dispatchEvent) {
        radios[idx].dispatchEvent(new Event('change', { bubbles: true }));
      }
      for (k = 0; k < panels.length; k++) {
        if (k === idx) {
          panels[k].removeAttribute('hidden');
        } else {
          panels[k].setAttribute('hidden', '');
        }
      }
      current = idx;
    }

    try {
      // Phase 1 — ARIA/tabindex/id attributes.
      setAttr(list, 'role', 'tablist');
      for (i = 0; i < labels.length; i++) {
        var radioId = radios[i].getAttribute('id');
        var m = radioId ? /^(.*)-(\d+)$/.exec(radioId) : null;
        var base = m ? m[1] : 'thallo-tabs';
        var nth = m ? m[2] : String(i + 1);
        var panelId = panels[i].getAttribute('id');
        if (!panelId) {
          panelId = base + '-panel-' + nth; // tabs-{blockid}-panel-N from the input id
          setAttr(panels[i], 'id', panelId);
        }
        var labelId = labels[i].getAttribute('id');
        if (!labelId) {
          labelId = base + '-tab-' + nth;
          setAttr(labels[i], 'id', labelId);
        }
        setAttr(labels[i], 'role', 'tab');
        setAttr(labels[i], 'aria-selected', i === current ? 'true' : 'false');
        setAttr(labels[i], 'aria-controls', panelId);
        setAttr(labels[i], 'tabindex', i === current ? '0' : '-1');
        setAttr(panels[i], 'role', 'tabpanel');
        setAttr(panels[i], 'aria-labelledby', labelId);
        setAttr(panels[i], 'tabindex', '-1');
        if (i !== current) { setAttr(panels[i], 'hidden', ''); }
      }

      // Phase 2 — event listeners (automatic activation: focus + select together).
      listen(list, 'keydown', function (e) {
        var next = null;
        if (e.key === 'ArrowRight') {
          next = (current + 1) % labels.length;
        } else if (e.key === 'ArrowLeft') {
          next = (current - 1 + labels.length) % labels.length;
        } else if (e.key === 'Home') {
          next = 0;
        } else if (e.key === 'End') {
          next = labels.length - 1;
        }
        if (next === null) { return; }
        if (e.preventDefault) { e.preventDefault(); }
        select(next);
        if (labels[next].focus) { labels[next].focus(); }
      });
      for (i = 0; i < labels.length; i++) {
        (function (idx) {
          listen(labels[idx], 'click', function (e) {
            // The module, not the label's native label->radio forwarding,
            // drives radio state and panel visibility.
            if (e.preventDefault) { e.preventDefault(); }
            select(idx);
          });
        })(i);
      }

      // Phase 3 — hide the radios LAST; the core's marker lands immediately
      // after this function returns, flipping the CSS to enhanced mode.
      for (i = 0; i < radios.length; i++) {
        setAttr(radios[i], 'hidden', '');
        setAttr(radios[i], 'tabindex', '-1');
        setAttr(radios[i], 'aria-hidden', 'true');
      }
    } catch (err) {
      rollback();
      throw err; // core containment leaves the component unmarked
    }
  }

  window.ThalloRuntime.register('tabs', {
    selector: '.thallo-block-tabs',
    enhance: enhanceTabs
  });
})();
/* tabs:end */

/* boot:footer */
/* The ONE boot scheduler (spec §1 "Boot ordering is explicit").
   Runs after every module registration above AND after the element sections define
   their tags: customElements.define() upgrades already-parsed hosts synchronously,
   queuing their connection microtasks — so scheduling the whole-document scan on a
   LATER microtask (or on DOMContentLoaded, whose dispatch flushes those microtasks
   first) guarantees element projection wins before the legacy scan, which then
   no-ops on the shared marker. No public start() API. */
(function () {
  'use strict';
  function boot() { window.ThalloRuntime.enhance(document.documentElement); }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    Promise.resolve().then(boot);
  }
})();
