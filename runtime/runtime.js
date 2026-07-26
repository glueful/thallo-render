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
