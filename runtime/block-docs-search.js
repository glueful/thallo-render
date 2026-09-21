/* Thallo block asset: docs-search (website plan, phase 2c). Loaded lazily via
   block_script('docs-search'); same contract as block-code.js (may run more than once, maybe
   before ThalloRuntime exists). The form ships `hidden`: without this script there is no search
   box rather than a dead one. It queries the public search API (GET /v1/search) as the visitor
   types and lists the hits under the input as a combobox: arrows move, Enter opens, Escape
   closes, "/" focuses. A snippet arrives escaped with <mark> around the matches; it is rebuilt
   from text nodes and <mark> alone, so nothing else in it can ever be markup. */
(function () {
  'use strict';
  if (window.__thalloDocsSearch) { return; }
  var RT = window.ThalloRuntime;
  if (!RT || typeof RT.register !== 'function') { return; }
  var seq = 0;

  function snippetInto(el, html) {
    var tpl = document.createElement('template');
    tpl.innerHTML = html; // inert: a template's content never runs or loads
    Array.prototype.forEach.call(tpl.content.childNodes, function (node) {
      if (node.nodeType === 1 && node.tagName === 'MARK') {
        var mark = document.createElement('mark');
        mark.textContent = node.textContent;
        el.appendChild(mark);
      } else {
        el.appendChild(document.createTextNode(node.textContent));
      }
    });
  }

  function enhance(form) {
    var input = form.querySelector('input');
    var list = form.querySelector('[role="listbox"]');
    var status = form.querySelector('[data-docs-search-status]');
    if (!input || !list || !status) { return false; }
    var id = 'docs-search-' + (++seq);
    var timer = null, controller = null, active = -1, options = [];
    list.id = id;
    input.setAttribute('aria-controls', id);

    function close() {
      list.hidden = true; status.hidden = true; active = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }
    function say(text) { list.hidden = true; status.textContent = text; status.hidden = false; }
    function move(to) {
      if (!options.length) { return; }
      if (active >= 0) { options[active].setAttribute('aria-selected', 'false'); }
      active = (to + options.length) % options.length;
      options[active].setAttribute('aria-selected', 'true');
      input.setAttribute('aria-activedescendant', options[active].id);
      options[active].scrollIntoView({ block: 'nearest' });
    }
    function show(hits, q) {
      list.textContent = ''; options = []; active = -1;
      if (!hits.length) { say('No results for “' + q + '”.'); return; }
      hits.forEach(function (hit, i) {
        var li = document.createElement('li');
        li.id = id + '-' + i;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        var a = document.createElement('a');
        a.href = hit.href; a.tabIndex = -1; a.className = 'docs-search__hit';
        var title = document.createElement('span');
        title.className = 'docs-search__title'; title.textContent = hit.title;
        var snippet = document.createElement('span');
        snippet.className = 'docs-search__snippet';
        snippetInto(snippet, String(hit.snippet || ''));
        a.appendChild(title); a.appendChild(snippet); li.appendChild(a); list.appendChild(li);
        options.push(li);
      });
      status.hidden = true; list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }
    function search() {
      var q = input.value.trim();
      if (controller) { controller.abort(); controller = null; }
      if (q.length < 2) { close(); return; }
      controller = typeof AbortController === 'function' ? new AbortController() : null;
      var url = '/v1/search?limit=8&q=' + encodeURIComponent(q)
        + '&locale=' + encodeURIComponent(form.getAttribute('data-locale') || 'en')
        + '&type=' + encodeURIComponent(form.getAttribute('data-type') || '');
      fetch(url, { headers: { Accept: 'application/json' }, signal: controller ? controller.signal : undefined })
        .then(function (res) { if (!res.ok) { throw new Error(String(res.status)); } return res.json(); })
        .then(function (json) {
          if (input.value.trim() !== q) { return; } // a later keystroke owns the list
          show(((json && json.data) || {}).hits || [], q);
        })
        .catch(function (err) { if (!err || err.name !== 'AbortError') { say('Search is unavailable right now.'); } });
    }
    function onInput() { if (timer) { clearTimeout(timer); } timer = setTimeout(search, 160); }
    function onKeydown(evt) {
      if (evt.key === 'ArrowDown') { evt.preventDefault(); move(active + 1); }
      else if (evt.key === 'ArrowUp') { evt.preventDefault(); move(active < 0 ? -1 : active - 1); }
      else if (evt.key === 'Escape') { close(); }
      else if (evt.key === 'Enter') {
        evt.preventDefault();
        var target = options[active < 0 ? 0 : active];
        if (target) { window.location.href = target.querySelector('a').href; }
      }
    }
    function onSubmit(evt) { evt.preventDefault(); }
    function onDocClick(evt) { if (!form.contains(evt.target)) { close(); } }
    function onSlash(evt) {
      var t = evt.target, tag = t && t.tagName;
      if (evt.key !== '/' || evt.metaKey || evt.ctrlKey || evt.altKey) { return; }
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) { return; }
      evt.preventDefault(); input.focus();
    }

    input.addEventListener('input', onInput);
    input.addEventListener('keydown', onKeydown);
    input.addEventListener('focus', onInput);
    form.addEventListener('submit', onSubmit);
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onSlash);
    form.hidden = false;
    return function () {
      if (timer) { clearTimeout(timer); }
      if (controller) { controller.abort(); }
      input.removeEventListener('input', onInput);
      input.removeEventListener('keydown', onKeydown);
      input.removeEventListener('focus', onInput);
      form.removeEventListener('submit', onSubmit);
      document.removeEventListener('click', onDocClick);
      document.removeEventListener('keydown', onSlash);
      form.hidden = true;
    };
  }

  RT.register('docs-search', { selector: '[data-docs-search]', enhance: enhance });
  window.__thalloDocsSearch = true;
  RT.enhance(document.documentElement);
})();
