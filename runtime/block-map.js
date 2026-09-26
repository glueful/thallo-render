/* Thallo block asset: map (click to load). Loaded lazily via block_script('map'); may execute
   more than once and possibly before ThalloRuntime exists (same contract as block-code.js).
   The floor is a notice and a link to Google Maps; this reveals the Show map button and, on its
   click, puts Google's map in the placeholder — nothing of Google loads before that. Skipped on
   the stage (the canvas default), where the placeholder is what a visitor first sees. */
(function () {
  'use strict';
  if (window.__thalloBlockMap) { return; }
  var RT = window.ThalloRuntime;
  if (!RT || typeof RT.register !== 'function') { return; }

  function enhance(root) {
    var src = root.getAttribute('data-map-src');
    var button = root.querySelector('.thallo-block-map__load');
    if (!src || src.indexOf('https://www.google.com/maps') !== 0 || !button) { return false; }

    function load(evt) {
      evt.preventDefault();
      var frame = document.createElement('iframe');
      frame.className = 'thallo-block-map__map';
      frame.src = src;
      frame.title = root.getAttribute('data-map-title') || 'Map';
      frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
      frame.setAttribute('allowfullscreen', '');
      while (root.firstChild) { root.removeChild(root.firstChild); }
      root.appendChild(frame);
      root.classList.add('thallo-block-map__consent--loaded');
    }

    button.hidden = false;
    button.addEventListener('click', load);
    return function () {
      button.removeEventListener('click', load);
      button.hidden = true;
    };
  }

  RT.register('map', { selector: '.thallo-block-map__consent', enhance: enhance });
  window.__thalloBlockMap = true;
  RT.enhance(document.documentElement);
})();
