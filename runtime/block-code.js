/* Thallo block asset: code (website plan, phase 1). Loaded lazily via
   block_script('code'); may execute more than once and possibly before
   ThalloRuntime exists (same contract as block-gallery.js). Adds a Copy button
   to each enhanced block: the floor is the plain <pre><code>, so without JS
   there is no dead control. */
(function () {
  'use strict';
  if (window.__thalloBlockCode) { return; }
  var RT = window.ThalloRuntime;
  if (!RT || typeof RT.register !== 'function') { return; }

  function enhance(root) {
    if (root.getAttribute('data-copy') !== '1') { return false; }
    var actions = root.querySelector('.thallo-block-code__actions');
    var code = root.querySelector('code');
    if (!actions || !code) { return false; }

    var button = document.createElement('button');
    button.setAttribute('type', 'button');
    button.className = 'thallo-block-code__copy';
    button.textContent = 'Copy';
    var timer = null;

    function done(label) {
      button.textContent = label;
      if (timer) { clearTimeout(timer); }
      timer = setTimeout(function () { button.textContent = 'Copy'; timer = null; }, 2000);
    }
    function fallback(text) {
      var area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.style.position = 'absolute';
      area.style.left = '-9999px';
      document.body.appendChild(area);
      area.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(area);
      return ok;
    }
    function onClick(evt) {
      evt.preventDefault();
      var text = code.textContent;
      var clip = window.navigator && window.navigator.clipboard;
      if (clip && typeof clip.writeText === 'function') {
        clip.writeText(text).then(function () { done('Copied'); }, function () { done(fallback(text) ? 'Copied' : 'Copy failed'); });
      } else {
        done(fallback(text) ? 'Copied' : 'Copy failed');
      }
    }

    button.addEventListener('click', onClick);
    actions.appendChild(button);
    return function () {
      if (timer) { clearTimeout(timer); }
      if (button.parentNode === actions) { actions.removeChild(button); }
    };
  }

  RT.register('code', { selector: '.thallo-block-code', enhance: enhance });
  window.__thalloBlockCode = true;
  RT.enhance(document.documentElement);
})();
