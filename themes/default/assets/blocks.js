/* COMPATIBILITY LOADER ONLY (theme-runtime spec §2.2) — the behavioral runtime moved to
   the package-owned /_thallo/runtime/runtime.js (see packages/thallo-render/runtime/).
   This file exists for ONE compatibility release so already-cached default-layout HTML
   that still references asset('blocks.js') keeps working; it must contain NO behavior.
   Removal is tracked in spec §11.4. */
(function () {
  'use strict';
  if (window.ThalloRuntime) { return; } // new layout already loaded the runtime
  var s = document.createElement('script');
  s.defer = true;
  s.src = '/_thallo/runtime/runtime.js';
  document.head.appendChild(s);
})();
