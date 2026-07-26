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
