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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
