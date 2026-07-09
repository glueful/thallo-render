/* Starter-block progressive enhancement (block-library spec §2) — currently
   carousel controls only. The scroll-snap base works with no JS at all; this
   script adds arrows / dots / autoplay where the block asked for them.

   HARD RULE: no-ops entirely in the canvas stage (annotation wrappers
   present) — injected controls would diverge wrapper HTML from fetched HTML
   and break the canvas patch gate; the canvas shows the scroll-snap base. */
(function () {
  'use strict';

  function init() {
    if (document.querySelector('.thallo-preview-block')) return; // canvas stage: no-op
    document.querySelectorAll('.thallo-block-carousel').forEach(enhance);
    document.querySelectorAll('.thallo-block-navigation').forEach(enhanceNav);
  }

  function enhance(root) {
    var viewport = root.querySelector('.thallo-block-carousel__viewport');
    var track = root.querySelector('.thallo-block-carousel__track');
    if (!viewport || !track) return;
    var slides = Array.prototype.filter.call(track.children, function (el) {
      return el.nodeType === 1;
    });
    if (slides.length < 2) return;

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var timer = null;

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
    function goTo(i) {
      var n = slides.length;
      viewport.scrollTo({ left: slideStart(((i % n) + n) % n), behavior: 'smooth' });
    }
    function stopAutoplay() {
      if (timer) { clearInterval(timer); timer = null; }
    }

    if (root.dataset.arrows === '1') {
      var prev = button('thallo-block-carousel__prev', 'Previous slide', '‹');
      var next = button('thallo-block-carousel__next', 'Next slide', '›');
      prev.addEventListener('click', function () { stopAutoplay(); goTo(currentIndex() - 1); });
      next.addEventListener('click', function () { stopAutoplay(); goTo(currentIndex() + 1); });
      root.appendChild(prev);
      root.appendChild(next);
    }

    var dots = [];
    if (root.dataset.dots === '1') {
      var wrap = document.createElement('div');
      wrap.className = 'thallo-block-carousel__dots';
      slides.forEach(function (_, i) {
        var dot = button('thallo-block-carousel__dot', 'Go to slide ' + (i + 1), '');
        dot.addEventListener('click', function () { stopAutoplay(); goTo(i); });
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
      timer = setInterval(function () { goTo(currentIndex() + 1); }, 5000);
      ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (ev) {
        viewport.addEventListener(ev, stopAutoplay, { passive: true, once: true });
      });
    }
  }

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

  /* Navigation enhancement — upgrades the CSS-only submenu floor to a real menu:
     hover-intent (open on enter, close on a short delay), click/tap toggling,
     keyboard (Enter/Space/ArrowDown open + focus first link, Escape close), and
     outside-click close. Concepts follow the WAI-ARIA menu pattern (as Reka /
     Nuxt UI implement it) reimplemented minimally — no framework dependency.
     Setting --js on the root lets navigation.css hand submenu display to
     .is-open so the hover-intent delay governs (see the --js rules there). */
  function enhanceNav(root) {
    var parents = root.querySelectorAll('.thallo-block-navigation__item--parent');
    if (!parents.length) return;
    var revealHover = root.classList.contains('thallo-block-navigation--reveal-hover');
    root.classList.add('thallo-block-navigation--js');

    parents.forEach(function (li) {
      var toggle = li.querySelector('[data-nav-toggle]');
      var details = li.querySelector('.thallo-block-navigation__details');
      var closeTimer = null;

      function firstLink() {
        return li.querySelector('.thallo-block-navigation__sublink, .thallo-block-navigation__col-title');
      }
      function isOpen() {
        return details ? details.open : li.classList.contains('is-open');
      }
      function open() {
        if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
        closeOthers(li);
        if (details) { details.open = true; } else { li.classList.add('is-open'); }
        if (toggle && toggle.tagName !== 'SUMMARY') toggle.setAttribute('aria-expanded', 'true');
      }
      function close() {
        if (details) { details.open = false; } else { li.classList.remove('is-open'); }
        if (toggle && toggle.tagName !== 'SUMMARY') toggle.setAttribute('aria-expanded', 'false');
      }
      li._navClose = close;

      // Hover-intent (hover mode only; details/click mode opens natively).
      if (revealHover && !details) {
        li.addEventListener('mouseenter', open);
        li.addEventListener('mouseleave', function () {
          closeTimer = setTimeout(close, 180);
        });
      }
      // Click/tap toggle for hover-mode triggers (details toggles itself).
      if (toggle && !details) {
        toggle.addEventListener('click', function (e) {
          if (!isOpen()) {
            if (toggle.tagName === 'A') e.preventDefault(); // first tap opens, not navigate
            open();
          } else if (toggle.tagName !== 'A') {
            close();
          }
        });
      }
      // Keyboard: open + dive into the panel; Escape closes and returns focus.
      if (toggle) {
        toggle.addEventListener('keydown', function (e) {
          if (e.key === 'ArrowDown') {
            e.preventDefault();
            open();
            var f = firstLink(); if (f) f.focus();
          } else if (e.key === 'Escape') {
            close();
          }
        });
      }
      li.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) {
          close();
          if (toggle) toggle.focus();
        }
      });
    });

    function closeOthers(except) {
      parents.forEach(function (li) {
        if (li !== except && !li.contains(except) && typeof li._navClose === 'function') li._navClose();
      });
    }
    document.addEventListener('click', function (e) {
      if (!root.contains(e.target)) {
        parents.forEach(function (li) { if (typeof li._navClose === 'function') li._navClose(); });
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

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

/* form-block:start — progressive enhancement for [data-thallo-form].
   No-JS baseline is a normal POST to /_forms/submit (PRG). With JS, we intercept,
   POST via fetch with Accept: application/json, and render the result inline. The
   server returns the SAME semantics for both paths (form-block spec §6). */
(function () {
  'use strict';
  function setResult(box, message, ok) {
    if (!box) return;
    box.textContent = message;
    box.classList.remove('thallo-block-form__result--error', 'thallo-block-form__result--ok');
    box.classList.add(ok ? 'thallo-block-form__result--ok' : 'thallo-block-form__result--error');
  }
  document.querySelectorAll('form[data-thallo-form]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var box = form.querySelector('.thallo-block-form__result');
      var btn = form.querySelector('.thallo-block-form__submit');
      if (btn) btn.disabled = true;
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' }
      }).then(function (res) {
        return res.json().catch(function () { return {}; });
      }).then(function (json) {
        if (json && json.ok) {
          form.reset();
          setResult(box, json.message || 'Thanks — your message has been sent.', true);
        } else {
          setResult(box, (json && json.error) || 'Please check your entries and try again.', false);
        }
      }).catch(function () {
        setResult(box, 'Something went wrong. Please try again.', false);
      }).then(function () {
        if (btn) btn.disabled = false;
      });
    });
  });
})();
/* form-block:end */
