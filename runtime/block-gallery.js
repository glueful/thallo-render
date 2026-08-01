/* Thallo block asset: gallery (modern-blocks spec §4). Loaded lazily via
   block_script('gallery'); may execute MORE than once (fragment renders emit
   duplicate tags) and possibly BEFORE ThalloRuntime exists — the guard burns only
   after registration succeeds, and success immediately self-enhances so late
   registration (after the runtime's boot pass) still enhances existing blocks. */
(function () {
  'use strict';
  if (window.__thalloBlockGallery) { return; }
  var RT = window.ThalloRuntime;
  if (!RT || typeof RT.register !== 'function') { return; } // retry on a later execution

  function enhance(root) {
    if (root.getAttribute('data-lightbox') !== '1') { return false; } // opt-out: floor is final
    var anchors = [];
    var found = root.querySelectorAll('.thallo-block-gallery__item');
    for (var i = 0; i < found.length; i++) { anchors.push(found[i]); }
    if (anchors.length === 0) { return false; }

    var dialog = null;
    var current = 0;
    var lastTrigger = null;
    var undo = [];

    function supported() {
      return typeof HTMLDialogElement === 'function' &&
        typeof HTMLDialogElement.prototype.showModal === 'function';
    }
    function build() {
      var d = document.createElement('dialog');
      d.className = 'thallo-block-gallery__dialog';
      d.innerHTML =
        '<img alt="">' +
        '<div class="thallo-block-gallery__bar">' +
        '<button type="button" class="thallo-block-gallery__prev" aria-label="Previous image">‹</button>' +
        '<span class="thallo-block-gallery__status" aria-live="polite"></span>' +
        '<button type="button" class="thallo-block-gallery__next" aria-label="Next image">›</button>' +
        '<button type="button" class="thallo-block-gallery__close" aria-label="Close">×</button>' +
        '</div>';
      d.querySelector('.thallo-block-gallery__prev').addEventListener('click', function () { show(current - 1); });
      d.querySelector('.thallo-block-gallery__next').addEventListener('click', function () { show(current + 1); });
      d.querySelector('.thallo-block-gallery__close').addEventListener('click', function () { d.close(); });
      d.addEventListener('close', function () {
        if (lastTrigger && lastTrigger.focus) { lastTrigger.focus(); } // explicit focus restore
      });
      return d;
    }
    function discardDialog() {
      if (!dialog) { return; }
      if (dialog.open && dialog.close) { try { dialog.close(); } catch (closeErr) {} }
      if (dialog.parentNode) { try { dialog.parentNode.removeChild(dialog); } catch (removeErr) {} }
      dialog = null;
    }
    function show(n) {
      var count = anchors.length;
      current = ((n % count) + count) % count;
      var a = anchors[current];
      var img = dialog.querySelector('img');
      img.src = a.getAttribute('href');
      img.alt = a.getAttribute('aria-label') || '';
      dialog.querySelector('.thallo-block-gallery__status').textContent =
        (current + 1) + ' of ' + count;
    }
    function onClick(e) {
      var a = e.target && e.target.closest ? e.target.closest('.thallo-block-gallery__item') : null;
      if (!a || anchors.indexOf(a) === -1) { return; }
      if (!supported()) { return; } // anchor navigates normally
      try {
        if (!dialog) {
          dialog = build();       // build cannot leak: append happens only after return
          document.body.appendChild(dialog);
        }
        lastTrigger = a;
        show(anchors.indexOf(a));
        dialog.showModal(); // must SUCCEED before we cancel navigation
      } catch (err) {
        discardDialog();
        lastTrigger = null;
        return; // construction/showModal failure: leave the click untouched
      }
      e.preventDefault();
    }

    undo.push(function () { root.removeEventListener('click', onClick); });
    root.addEventListener('click', onClick);
    undo.push(discardDialog);

    return function () {
      for (var u = undo.length - 1; u >= 0; u--) {
        try { undo[u](); } catch (cleanupErr) {}
      }
    };
  }

  try {
    RT.register('gallery', { selector: '.thallo-block-gallery', enhance: enhance });
  } catch (err) {
    // register() threw (name already taken — e.g. guard was externally cleared while
    // the registry kept the module): stay unguarded, mutate nothing; the winning
    // execution's registration stands.
    return;
  }
  window.__thalloBlockGallery = true;
  RT.enhance(document.documentElement); // late-registration correctness authority
})();
