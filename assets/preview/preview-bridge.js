// Thallo canvas bridge (visual-canvas spec §3 + stage-toolbar spec §1–§3).
// SILENT until a canvas parent says hello; a plain preview tab never messages
// anyone. The nonce is a correlation token, not auth — it stops stale frames/
// same-window noise from impersonating the active canvas session. Token-free
// and static on purpose (cacheable). CSP pin (reworded, format-bubble spec):
// no style ATTRIBUTES ever appear in emitted or serialized markup and ALL
// appearance lives in preview.css classes; bridge-owned UI may be positioned
// via CSSOM property assignment (el.style.transform — geometry only), which
// strict style-src does not restrict. Block toolbars stay DOM-placed. Mirrors
// are DOM-only and applied ONLY on parent command, after the tree committed.
(function () {
  'use strict'
  var session = null // { origin, nonce }
  var selectedId = null
  var toolbar = null
  var anchorEl = null
  var editing = null // { id, field, kind, region, debounce }
  var lastPointer = null // { x, y } of the granting double-click (caret placement)
  var drag = null // { session, blocks, wrapper, external, zone, legal, indicator, ghost, scrollTimer }
  var selectedIds = [] // the parent's sibling multi-selection (highlight ids); selectedId is its anchor
  var suppressClick = false // one-shot: the click after a completed drag
  var linkPanel = null // { root, input } — child of the current bubble
  var savedLinkRange = null // session-scoped (link-panel spec); cleared by closeLinkPanel
  var linkPanelOpen = false // freeze flag (link-panel spec §4)
  // The displayed revision (visual builder spec §3.5): read off <main> at load and after
  // every patch; a fetched page older than it within the same epoch is refused.
  var displayed = revisionOf(document)

  function revisionOf(doc) {
    var main = doc && doc.querySelector ? doc.querySelector('main[data-thallo-revision]') : null
    if (!main) return null
    var revision = parseInt(main.getAttribute('data-thallo-revision'), 10)
    if (isNaN(revision)) return null
    var pair = { epoch: main.getAttribute('data-thallo-epoch') || '', revision: revision }
    // The style class generation the page was rendered from (visual builder spec §4.3).
    var generation = parseInt(main.getAttribute('data-thallo-style-generation'), 10)
    if (!isNaN(generation)) pair.style_generation = generation
    return pair
  }

  function withRevision(payload, pair) {
    if (pair) {
      payload.epoch = pair.epoch
      payload.revision = pair.revision
      if (typeof pair.style_generation === 'number') payload.style_generation = pair.style_generation
    }
    return payload
  }

  function post(type, payload) {
    if (!session) return
    var msg = { type: 'thallo:' + type, nonce: session.nonce }
    if (payload) {
      for (var key in payload) {
        if (Object.prototype.hasOwnProperty.call(payload, key)) msg[key] = payload[key]
      }
    }
    window.parent.postMessage(msg, session.origin)
  }

  function idsIndex() {
    return Array.prototype.map.call(
      document.querySelectorAll('[data-thallo-block]'),
      function (el) { return el.getAttribute('data-thallo-block') }
    )
  }

  function wrapperFor(target) {
    return target && target.closest ? target.closest('[data-thallo-block]') : null
  }

  function clearClass(cls) {
    Array.prototype.forEach.call(document.querySelectorAll('.' + cls), function (el) {
      el.classList.remove(cls)
    })
  }

  function cssEscape(value) {
    // window.CSS.escape everywhere modern; quote/backslash fallback keeps the
    // selector safe for the nanoid-alphabet ids we actually emit.
    return window.CSS && window.CSS.escape
      ? window.CSS.escape(String(value))
      : String(value).replace(/["\\]/g, '\\$&')
  }

  function findBlock(id) {
    return document.querySelector('[data-thallo-block="' + cssEscape(id) + '"]')
  }

  // ── Toolbar (stage-toolbar spec §3) ─────────────────────────────────────────
  var ACTIONS = [
    { action: 'drag', label: 'Drag to reorder', path: 'M9 5h.01M9 12h.01M9 19h.01M15 5h.01M15 12h.01M15 19h.01' },
    { action: 'move-up', label: 'Move up', path: 'M18 15l-6-6-6 6' },
    { action: 'move-down', label: 'Move down', path: 'M6 9l6 6 6-6' },
    { action: 'duplicate', label: 'Duplicate', path: 'M8 8h12v12H8zM16 8V4H4v12h4' },
    { action: 'delete', label: 'Delete', path: 'M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 10v6M14 10v6' },
    { action: 'add-after', label: 'Add block after', path: 'M12 5v14M5 12h14' }
  ]

  function ensureToolbar() {
    if (toolbar) return toolbar
    toolbar = document.createElement('div')
    toolbar.className = 'thallo-canvas-toolbar'
    ACTIONS.forEach(function (a) {
      var btn = document.createElement('button')
      btn.type = 'button'
      btn.setAttribute('data-action', a.action)
      btn.setAttribute('aria-label', a.label)
      btn.innerHTML =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
        'stroke-linecap="round" stroke-linejoin="round"><path d="' + a.path + '"/></svg>'
      toolbar.appendChild(btn)
    })
    toolbar.querySelector('[data-action="drag"]').addEventListener('pointerdown', onGripDown)
    return toolbar
  }

  // Elements whose children never RENDER (void/replaced/foreign roots): the
  // toolbar cannot live inside them — a bridge-owned shim sibling hosts it.
  var NO_CHILD_HOSTS = {
    HR: 1, IMG: 1, BR: 1, INPUT: 1, EMBED: 1, IFRAME: 1, OBJECT: 1,
    CANVAS: 1, VIDEO: 1, AUDIO: 1, svg: 1, SVG: 1
  }

  // Metadata elements that render nothing: a block may own a <style> (the Style
  // block's scoped skin) — it must never be treated as the visual host. Resolve a
  // wrapper's host by skipping these when scanning its element children.
  var NON_VISUAL_HOSTS = { STYLE: 1, SCRIPT: 1, LINK: 1, TEMPLATE: 1 }
  function firstVisualChild(el) {
    var c = el.firstElementChild
    while (c && NON_VISUAL_HOSTS[c.tagName]) c = c.nextElementSibling
    return c
  }

  function detachToolbar() {
    if (toolbar && toolbar.parentNode) toolbar.parentNode.removeChild(toolbar)
    if (anchorEl) {
      if (anchorEl.classList.contains('thallo-canvas-shim') && anchorEl.parentNode) {
        anchorEl.parentNode.removeChild(anchorEl) // bridge-owned — remove entirely
      } else {
        anchorEl.classList.remove('thallo-canvas-anchor')
      }
      anchorEl = null
    }
  }

  /** A block inside a hidden tab panel: check that panel's radio so the selection is in view. */
  function revealTabPanels(w) {
    var panel = w.parentElement ? w.parentElement.closest('.thallo-block-tabs__panel') : null
    while (panel) {
      var root = panel.closest('.thallo-block-tabs')
      var panels = root ? Array.prototype.slice.call(root.querySelectorAll(':scope > .thallo-block-tabs__panels > .thallo-block-tabs__panel')) : []
      var radios = root ? Array.prototype.slice.call(root.querySelectorAll(':scope > .thallo-block-tabs__radio')) : []
      var radio = radios[panels.indexOf(panel)]
      if (radio) radio.checked = true
      panel = root && root.parentElement ? root.parentElement.closest('.thallo-block-tabs__panel') : null
    }
  }
  function selectWrapper(w) {
    revealTabPanels(w)
    clearClass('thallo-canvas-selected')
    detachToolbar()
    w.classList.add('thallo-canvas-selected')
    selectedId = w.getAttribute('data-thallo-block')
    selectedIds = [selectedId]
    var host = firstVisualChild(w)
    if (host && NO_CHILD_HOSTS[host.tagName]) {
      // hr/img/… render no children: anchor a shim sibling instead.
      var shim = document.createElement('span')
      shim.className = 'thallo-canvas-anchor thallo-canvas-shim'
      host.insertAdjacentElement('afterend', shim)
      anchorEl = shim
      shim.appendChild(ensureToolbar())
    } else if (host) {
      // DOM placement, static CSS (spec §3): anchor gets position:relative from
      // its class; the toolbar is absolute against it with constant offsets.
      // Text-only renders (no element child) get selection but no toolbar.
      anchorEl = host
      host.classList.add('thallo-canvas-anchor')
      host.insertBefore(ensureToolbar(), host.firstChild)
    }
  }

  function clearSelection() {
    clearClass('thallo-canvas-selected')
    detachToolbar()
    selectedId = null
    selectedIds = []
  }
  /** Ring the parent's whole selection (visual builder spec §5.5): the anchor keeps the toolbar. */
  function ringSelection(ids) {
    selectedIds = []
    for (var i = 0; i < ids.length; i++) {
      var w = findBlock(ids[i])
      if (!w) continue
      w.classList.add('thallo-canvas-selected')
      selectedIds.push(ids[i])
    }
    if (selectedId !== null && selectedIds.indexOf(selectedId) === -1) selectedIds.unshift(selectedId)
  }

  // ── Edit-in-place session (edit-in-place spec §3) ───────────────────────────
  function regionFor(id, field) {
    var w = findBlock(id)
    if (!w) return null
    var regions = w.querySelectorAll(
      '.thallo-edit-region[data-thallo-edit-block="' + cssEscape(id) + '"]'
        + '[data-thallo-edit-field="' + cssEscape(field) + '"]'
    )
    return regions.length === 1 ? regions[0] : null // one region per (block, field)
  }

  function singleRegionOf(id) {
    // The block's OWN regions only (keyboard-shortcuts review P1): a container
    // block's subtree includes nested child-block regions — counting those
    // would start editing a CHILD while the parent is the selected or
    // double-clicked block. Shared by keyboard Enter and the wrapper-level
    // double-click fallback so the two paths stay aligned.
    var w = findBlock(id)
    if (!w) return null
    var regions = w.querySelectorAll(
      '.thallo-edit-region[data-thallo-edit-block="' + cssEscape(id) + '"]'
    )
    return regions.length === 1 ? regions[0] : null
  }

  /**
   * Rewrite rich-region HTML into the save/render sanitizer's allowlist shape
   * (format-bar spec §2): b -> strong, i -> em, span[style] unwrapped. The
   * sanitizer drops disallowed elements WITH CHILDREN, so unnormalized native
   * Cmd+B output makes the bolded text itself vanish at the next apply.
   * ONLY ever called with the active edit region or its detached clone (spec
   * pin): theme markup may legitimately use b/i/styled spans elsewhere.
   * Children are MOVED (not cloned), so live selections anchored in text
   * nodes survive when this runs against the live region.
   */
  function normalizeRichRegion(root) {
    // STRIKE: execCommand('strikeThrough') emits <strike> in most engines —
    // not allowlisted either, so it joins the rename map (-> <s>). <u> and
    // <s> are allowlisted as-is.
    var rename = { B: 'strong', I: 'em', STRIKE: 's' }
    var el
    while ((el = root.querySelector('b, i, strike'))) {
      var next = document.createElement(rename[el.tagName])
      while (el.firstChild) next.appendChild(el.firstChild)
      el.parentNode.replaceChild(next, el)
    }
    while ((el = root.querySelector('span[style]'))) {
      while (el.firstChild) el.parentNode.insertBefore(el.firstChild, el)
      el.parentNode.removeChild(el)
    }
  }

  // ── In-stage formatting bar (format-bar spec §1/§3) ─────────────────────────
  var FORMAT_ACTIONS = [
    { format: 'bold', label: 'Bold', path: 'M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z' },
    { format: 'italic', label: 'Italic', path: 'M19 5h-8M13 19H5M15 5L9 19' },
    { format: 'underline', label: 'Underline', path: 'M6 4v6a6 6 0 0 0 12 0V4M4 20h16' },
    { format: 'strikethrough', label: 'Strikethrough', path: 'M16 4H9a3 3 0 0 0-2.83 4M14 12a4 4 0 0 1 0 8H6M4 12h16' },
    { format: 'link', label: 'Add link', path: 'M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1-1' },
    { format: 'unlink', label: 'Remove link', path: 'M15 7h2a5 5 0 0 1 0 10h-2M9 17H7A5 5 0 0 1 7 7h2M4 4l16 16' }
  ]

  function preventFocusSteal(e) {
    // Both pointerdown AND mousedown (spec pin): focus changes are
    // pointer-driven first on modern engines — cancelling only mousedown can
    // let the region blur (commit-and-exit) before the format action runs.
    // The link panel's INPUT is the one exemption: it must be focusable.
    if (e.target && e.target.tagName === 'INPUT') return
    e.preventDefault()
  }

  function showFormatBar() {
    // Body-mounted (format-bubble spec §1): structurally outside every
    // wrapper, so commits and duplicate clones can never carry it.
    var bar = document.createElement('div')
    bar.className = 'thallo-canvas-format-bar'
    // Two explicit rows (column layout): the bubble shrink-wraps the widest
    // row instead of stretching when the link panel opens.
    var row = document.createElement('div')
    row.className = 'thallo-canvas-format-row'
    FORMAT_ACTIONS.forEach(function (a) {
      var btn = document.createElement('button')
      btn.type = 'button'
      btn.setAttribute('data-format', a.format)
      btn.setAttribute('aria-label', a.label)
      btn.innerHTML =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
        'stroke-linecap="round" stroke-linejoin="round"><path d="' + a.path + '"/></svg>'
      row.appendChild(btn)
    })
    bar.appendChild(row)
    bar.addEventListener('pointerdown', preventFocusSteal)
    bar.addEventListener('mousedown', preventFocusSteal)
    document.body.appendChild(bar)
    return bar
  }

  /**
   * Show the bubble over the current selection, or hide it. Visible ONLY when
   * the selection is non-collapsed AND its common ancestor is contained by
   * the active region (strict containment — a partially-outside selection
   * resolves its ancestor above the region and hides). Geometry via CSSOM
   * transform only (reworded CSP pin); appearance stays in preview.css.
   */
  function positionFormatBubble() {
    if (!editing || !editing.formatBar) return
    if (linkPanelOpen) return // freeze (link-panel spec §4): focusing the input collapses the selection
    var bar = editing.formatBar
    var sel = window.getSelection ? window.getSelection() : null
    var placed = false
    if (sel && !sel.isCollapsed && sel.rangeCount > 0) {
      var range = sel.getRangeAt(0)
      if (editing.region.contains(range.commonAncestorContainer)) {
        var rect = range.getBoundingClientRect()
        var size = bar.getBoundingClientRect()
        var x = rect.left + rect.width / 2 - size.width / 2
        var maxX = (window.innerWidth || 0) - size.width - 4
        if (maxX > 4 && x > maxX) x = maxX
        if (x < 4) x = 4
        var y = rect.top - size.height - 8
        if (y < 4) y = rect.bottom + 8
        bar.style.transform = 'translate(' + x + 'px, ' + y + 'px)'
        placed = true
      }
    }
    if (placed) {
      bar.classList.add('thallo-canvas-format-visible')
      updateFormatStates(sel.getRangeAt(0))
    } else {
      bar.classList.remove('thallo-canvas-format-visible')
      clearFormatStates() // no-stale pin (polish batch §1): hidden = cleared
    }
  }

  // ── Bubble active-state (polish batch §1) ───────────────────────────────────
  var STATE_COMMANDS = {
    bold: 'bold', italic: 'italic', underline: 'underline', strikethrough: 'strikeThrough'
  }

  function queryState(cmd) {
    // Defensive: missing (jsdom) or THROWING (some engines throw on detached
    // selections) — the button simply stays inactive.
    if (!document.queryCommandState) return false
    try {
      return !!document.queryCommandState(cmd)
    } catch (err) {
      return false
    }
  }

  function updateFormatStates(range) {
    var bar = editing.formatBar
    for (var key in STATE_COMMANDS) {
      if (!Object.prototype.hasOwnProperty.call(STATE_COMMANDS, key)) continue
      var btn = bar.querySelector('[data-format="' + key + '"]')
      if (btn) btn.classList.toggle('thallo-canvas-format-active', queryState(STATE_COMMANDS[key]))
    }
    // Link/unlink state via containment (queryCommandState has no link
    // notion) — the same region-contained-<a> rule the panel prefill uses.
    var node = range.commonAncestorContainer
    var el = node && node.nodeType === 1 ? node : node && node.parentNode
    var a = el && el.closest ? el.closest('a') : null
    var linked = !!(a && editing.region.contains(a))
    var linkBtn = bar.querySelector('[data-format="link"]')
    var unlinkBtn = bar.querySelector('[data-format="unlink"]')
    if (linkBtn) linkBtn.classList.toggle('thallo-canvas-format-active', linked)
    if (unlinkBtn) unlinkBtn.classList.toggle('thallo-canvas-format-active', linked)
  }

  function clearFormatStates() {
    if (!editing || !editing.formatBar) return
    Array.prototype.forEach.call(
      editing.formatBar.querySelectorAll('.thallo-canvas-format-active'),
      function (el) { el.classList.remove('thallo-canvas-format-active') }
    )
  }

  function isSafeLinkUrl(url) {
    // Mirror of the safe_url/sanitizer posture (spec pin): the sanitizer
    // stays the authority — this check is UX honesty, a link that would be
    // stripped at save must never appear in the stage.
    if (typeof url !== 'string') return false
    var trimmed = url.replace(/^\s+|\s+$/g, '')
    if (trimmed === '') return false
    if (trimmed.slice(0, 2) === '//') return false // protocol-relative
    var m = /^([a-zA-Z][a-zA-Z0-9+.-]*):/.exec(trimmed)
    if (!m) return true // relative: /path, #anchor, ?q, bare path
    var scheme = m[1].toLowerCase()
    return scheme === 'http' || scheme === 'https' || scheme === 'mailto'
  }

  function runCommand(cmd, value) {
    // Real-command-path discipline (plan-review caution): normalization and
    // the commit schedule run ONLY when the engine actually executed the
    // command — a missing or throwing execCommand is a complete no-op.
    if (!document.execCommand) return false
    try {
      if (typeof value === 'string') document.execCommand(cmd, false, value)
      else document.execCommand(cmd)
      return true
    } catch (err) {
      return false
    }
  }

  function applyFormat(action) {
    if (!editing || editing.kind !== 'rich') return
    if (action === 'link') {
      toggleLinkPanel() // inline panel, never a browser prompt (link-panel spec §1)
      return
    }
    var ran = false
    if (action === 'unlink') {
      ran = runCommand('unlink')
    } else if (action === 'bold' || action === 'italic' || action === 'underline') {
      ran = runCommand(action)
    } else if (action === 'strikethrough') {
      ran = runCommand('strikeThrough') // the command's camelCase legacy name
    }
    if (!ran) return
    normalizeRichRegion(editing.region) // live pass: selection survives node MOVES
    onEditInput() // deterministic commit (spec §4): never rely on engines firing input
    positionFormatBubble() // re-anchor now: normalization reshapes the DOM (review caution)
  }

  // ── Inline link panel (link-panel spec §1–§4) ───────────────────────────────
  function ensureLinkPanel() {
    if (linkPanel && editing && linkPanel.root.parentNode === editing.formatBar) return linkPanel
    var root = document.createElement('div')
    root.className = 'thallo-canvas-link-panel'
    var input = document.createElement('input')
    input.type = 'text'
    input.placeholder = 'Paste a link…'
    input.setAttribute('aria-label', 'Link URL')
    var apply = document.createElement('button')
    apply.type = 'button'
    apply.setAttribute('data-link-apply', '')
    apply.setAttribute('aria-label', 'Apply link')
    apply.innerHTML =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round"><path d="M9 10l-5 5 5 5M4 15h11a4 4 0 0 0 0-8h-1"/></svg>'
    root.appendChild(input)
    root.appendChild(apply)
    input.addEventListener('keydown', onLinkInputKeydown)
    input.addEventListener('input', function () {
      root.classList.remove('thallo-canvas-link-invalid')
    })
    apply.addEventListener('click', function (e) {
      e.preventDefault()
      applyLink()
    })
    editing.formatBar.appendChild(root)
    linkPanel = { root: root, input: input }
    return linkPanel
  }

  function toggleLinkPanel() {
    if (linkPanelOpen) {
      closeLinkPanel()
      return
    }
    var sel = window.getSelection ? window.getSelection() : null
    if (!sel || sel.isCollapsed || sel.rangeCount === 0) return
    var range = sel.getRangeAt(0)
    if (!editing.region.contains(range.commonAncestorContainer)) return
    savedLinkRange = range.cloneRange()
    var panel = ensureLinkPanel()
    // Prefill from the closest <a> ONLY when it lives inside the region
    // (spec pin): a link-like theme wrapper outside the region is ignored.
    var node = range.commonAncestorContainer
    var el = node.nodeType === 1 ? node : node.parentNode
    var a = el && el.closest ? el.closest('a') : null
    panel.input.value = a && editing.region.contains(a) ? a.getAttribute('href') || '' : ''
    panel.root.classList.remove('thallo-canvas-link-invalid')
    panel.root.classList.add('thallo-canvas-link-open')
    linkPanelOpen = true // freeze positioning BEFORE focus collapses the selection
    panel.input.focus()
  }

  /**
   * Idempotent single owner of the panel's lifecycle state (review caution):
   * saved range, invalid mark, open/freeze flag. Called from the link
   * toggle, Escape, apply success, and endEditing — edit-flush ends the
   * session, so it funnels through endEditing too. A range is never reused
   * across sessions (spec pin).
   */
  function closeLinkPanel() {
    savedLinkRange = null
    linkPanelOpen = false
    if (linkPanel) {
      linkPanel.root.classList.remove('thallo-canvas-link-open')
      linkPanel.root.classList.remove('thallo-canvas-link-invalid')
    }
  }

  function onLinkInputKeydown(e) {
    if (e.key === 'Enter') {
      e.preventDefault()
      applyLink()
    }
    if (e.key === 'Escape') {
      e.preventDefault()
      e.stopPropagation()
      closeLinkPanel()
      if (editing) editing.region.focus()
      positionFormatBubble()
    }
  }

  function applyLink() {
    if (!editing || !linkPanel || !linkPanelOpen) return
    var url = linkPanel.input.value.replace(/^\s+|\s+$/g, '')
    if (!isSafeLinkUrl(url)) {
      // Invalid (empty included — spec pin: empty is NOT unlink): keep the
      // panel open with the VALUE preserved and focus in the input.
      linkPanel.root.classList.add('thallo-canvas-link-invalid')
      linkPanel.input.focus()
      return
    }
    if (!savedLinkRange) {
      closeLinkPanel()
      return
    }
    editing.region.focus()
    var sel = window.getSelection ? window.getSelection() : null
    if (sel && sel.removeAllRanges && sel.addRange) {
      sel.removeAllRanges()
      sel.addRange(savedLinkRange) // order pin: range ACTIVE before createLink
    }
    if (!runCommand('createLink', url)) return // v8 discipline: no side effects
    normalizeRichRegion(editing.region)
    onEditInput()
    closeLinkPanel() // success: AFTER command/normalize/commit scheduling
    positionFormatBubble()
  }

  function commitEditing() {
    if (!editing) return
    if (editing.debounce) clearTimeout(editing.debounce)
    if (editing.kind === 'rich') {
      // Normalize a DETACHED CLONE (format-bar spec §2): commit must be
      // allowlist-shaped even for HTML the bar never produced (native
      // Cmd+B/Cmd+I, rich paste) — and rewriting the live DOM mid-typing
      // would move the caret.
      var clone = editing.region.cloneNode(true)
      normalizeRichRegion(clone)
      post('text-changed', { id: editing.id, field: editing.field, html: clone.innerHTML })
    } else {
      var text = editing.region.innerText
      if (typeof text !== 'string') text = editing.region.textContent || ''
      post('text-changed', { id: editing.id, field: editing.field, text: text })
    }
  }

  function endEditing() {
    if (!editing) return
    if (editing.debounce) clearTimeout(editing.debounce)
    editing.region.removeAttribute('contenteditable')
    editing.region.classList.remove('thallo-canvas-editing')
    editing.region.removeEventListener('input', onEditInput)
    editing.region.removeEventListener('blur', onEditBlur)
    editing.region.removeEventListener('keydown', onEditKeydown)
    window.removeEventListener('blur', onWindowBlur)
    if (editing.formatBar) {
      // Cleanup BEFORE editing = null (review caution): the listeners and the
      // bubble element both hang off the session. closeLinkPanel clears the
      // saved range + freeze state (idempotent single owner); the panel
      // element itself is removed with the bubble below.
      closeLinkPanel()
      linkPanel = null
      document.removeEventListener('selectionchange', positionFormatBubble)
      window.removeEventListener('scroll', positionFormatBubble, true)
      window.removeEventListener('resize', positionFormatBubble)
      if (editing.formatBar.parentNode) {
        editing.formatBar.parentNode.removeChild(editing.formatBar)
      }
    }
    var id = editing.id
    editing = null
    post('edit-end', { id: id })
  }

  function onEditInput() {
    if (!editing) return
    if (editing.debounce) clearTimeout(editing.debounce)
    editing.debounce = setTimeout(commitEditing, 400)
  }

  function onWindowBlur() {
    // Focus left the stage WINDOW entirely (a click into the admin inspector,
    // a tab switch): commit-and-end. Cross-frame focus moves don't reliably
    // fire the region's own blur, and a session that outlives stage focus
    // wedges the parent's auto-apply suppression — editSessionActive never
    // clears, so inspector edits stop auto-applying until something else
    // (a stage click, the Apply button's flush) ends the session.
    commitEditing()
    endEditing()
  }

  function onEditBlur(e) {
    // Focus visiting the bubble (link panel) keeps the session alive
    // (link-panel spec §2). A null relatedTarget is treated as a REAL
    // outside blur (review caution): commit-and-exit as before.
    if (
      e && e.relatedTarget && editing && editing.formatBar
      && editing.formatBar.contains(e.relatedTarget)
    ) {
      return
    }
    commitEditing()
    endEditing()
  }

  function onEditKeydown(e) {
    if (e.key === 'Escape') {
      e.preventDefault()
      commitEditing()
      endEditing()
    }
    if (e.key === 'Enter' && editing && editing.kind === 'string') {
      e.preventDefault() // single-line: Enter commits-and-exits
      commitEditing()
      endEditing()
    }
  }

  function startEditing(id, field, kind) {
    if (editing) return
    var region = regionFor(id, field)
    if (!region) return
    detachToolbar()
    editing = { id: id, field: field, kind: kind, region: region, debounce: null, formatBar: null }
    region.setAttribute('contenteditable', kind === 'rich' ? 'true' : 'plaintext-only')
    // Best-effort (spec pin): engines without plaintext-only support reflect a
    // different IDL value — fall back to 'true'. Commits read innerText for
    // plain kinds, so markup can never persist either way.
    if (kind !== 'rich' && String(region.contentEditable).toLowerCase() !== 'plaintext-only') {
      region.setAttribute('contenteditable', 'true')
    }
    region.classList.add('thallo-canvas-editing')
    if (kind === 'rich') {
      editing.formatBar = showFormatBar()
      document.addEventListener('selectionchange', positionFormatBubble)
      window.addEventListener('scroll', positionFormatBubble, true)
      window.addEventListener('resize', positionFormatBubble)
    }
    region.addEventListener('input', onEditInput)
    region.addEventListener('blur', onEditBlur)
    region.addEventListener('keydown', onEditKeydown)
    window.addEventListener('blur', onWindowBlur)
    region.focus()
    // Caret at the double-click point (spec pin) — best-effort: jsdom and old
    // engines lack caretRangeFromPoint; focus() alone is the fallback.
    if (lastPointer && document.caretRangeFromPoint) {
      var range = document.caretRangeFromPoint(lastPointer.x, lastPointer.y)
      if (range && region.contains(range.startContainer)) {
        var sel = window.getSelection()
        if (sel) {
          sel.removeAllRanges()
          sel.addRange(range)
        }
      }
    }
    // Session lifecycle (auto-apply spec §1): posted ONLY when a session
    // actually started — a failed grant must never wedge parent suppression.
    post('edit-start', { id: id })
  }

  // ── Proposal drag (visual builder spec §5.3, §5.4): the tree is untouched until drop ───
  // Movement derives a drop zone from the real slot element under the pointer — parent, slot,
  // index, layout — and posts it as a proposal; the parent answers its legality, the indicator
  // shows it; pointerup posts the drop, Escape and pointercancel post a cancel. A parent-
  // originated drag (palette, outline) drives the same zones through drag-begin / drag-hover /
  // drag-end. Nothing reorders here: the parent applies the operation and the stage patches.
  function newSession() {
    return 'd' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36)
  }
  function layoutOf(el) {
    var cs = window.getComputedStyle ? window.getComputedStyle(el) : null
    if (!cs) return 'linear-vertical'
    var display = cs.display || ''
    if (display.indexOf('grid') !== -1) return 'other'
    if (display.indexOf('flex') !== -1) {
      var dir = cs.flexDirection || 'row'
      var wrap = cs.flexWrap || 'nowrap'
      if (dir.indexOf('reverse') !== -1 || wrap !== 'nowrap') return 'other'
      if (dir === 'row') return (cs.direction || 'ltr') === 'rtl' ? 'other' : 'linear-horizontal'
      return 'linear-vertical'
    }
    return 'linear-vertical'
  }
  function excluded(el, exclude) {
    for (var i = 0; i < exclude.length; i++) {
      if (exclude[i] === el || exclude[i].contains(el)) return true
    }
    return false
  }
  function childWrappersOf(slotEl, exclude) {
    var out = []
    for (var i = 0; i < slotEl.children.length; i++) {
      var el = slotEl.children[i]
      if (!(el.hasAttribute && el.hasAttribute('data-thallo-block'))) continue
      if (excluded(el, exclude)) continue
      out.push(el)
    }
    return out
  }
  /** The wrappers of the session's blocks: the zones under them are refused locally. */
  function draggedWrappers() {
    var out = []
    if (!drag) return out
    for (var i = 0; i < drag.blocks.length; i++) {
      var w = findBlock(drag.blocks[i])
      if (w) out.push(w)
    }
    return out
  }
  /** The drop zone at a viewport point: null outside every slot or inside a dragged subtree. */
  function zoneAt(x, y, exclude) {
    var hit = document.elementFromPoint ? document.elementFromPoint(x, y) : null
    var slotEl = hit && hit.closest ? hit.closest('[data-thallo-slot]') : null
    if (!slotEl) return null
    if (excluded(slotEl, exclude)) return null
    var owner = slotEl.parentElement ? slotEl.parentElement.closest('[data-thallo-block]') : null
    var layout = layoutOf(slotEl)
    var kids = childWrappersOf(slotEl, exclude)
    var index = kids.length
    if (layout !== 'other') {
      for (var i = 0; i < kids.length; i++) {
        var host = firstVisualChild(kids[i])
        if (!host) continue
        var r = host.getBoundingClientRect()
        var before = layout === 'linear-horizontal' ? x < r.left + r.width / 2 : y < r.top + r.height / 2
        if (before) { index = i; break }
      }
    }
    return {
      parent: owner ? owner.getAttribute('data-thallo-block') : null,
      slot: slotEl.getAttribute('data-thallo-slot'),
      index: index,
      layout: layout,
      element: slotEl,
      children: kids
    }
  }
  function sameZone(a, b) {
    if (!a || !b) return a === b
    return a.parent === b.parent && a.slot === b.slot && a.index === b.index
  }
  function removeIndicator() {
    if (drag && drag.indicator && drag.indicator.parentNode) {
      drag.indicator.parentNode.removeChild(drag.indicator)
    }
    if (drag) drag.indicator = null
  }
  /** The insertion line: a real element placed where the block would land (no inline geometry). */
  function showIndicator(zone) {
    removeIndicator()
    var line = document.createElement('div')
    line.className = 'thallo-canvas-drop-line'
    if (zone.layout === 'linear-horizontal') line.className += ' thallo-canvas-drop-line--horizontal'
    if (zone.layout === 'other') {
      line.className += ' thallo-canvas-drop-line--other'
      line.setAttribute('data-hint', 'Placed last here; set the exact position in the outline')
    }
    var beforeEl = zone.children[zone.index] || null
    zone.element.insertBefore(line, beforeEl)
    drag.indicator = line
    applyLegality()
  }
  function applyLegality() {
    if (!drag || !drag.indicator) return
    var line = drag.indicator
    line.classList.remove('thallo-canvas-drop-line--refused')
    line.removeAttribute('title')
    if (drag.legal === false) {
      line.classList.add('thallo-canvas-drop-line--refused')
      if (drag.reason) line.setAttribute('title', drag.reason)
    }
  }
  function wireZone(zone) {
    if (sameZone(zone, drag.zone)) return
    drag.zone = zone
    drag.legal = null
    drag.reason = ''
    if (!zone) {
      removeIndicator()
      // The parent's strip and remembered zone clear with the indicator: one null proposal per
      // leave (sameZone above swallows the repeats while the pointer stays outside every slot).
      post('drag-propose', { session: drag.session, blocks: drag.blocks, zone: null })
      return
    }
    showIndicator(zone)
    post('drag-propose', {
      session: drag.session,
      blocks: drag.blocks,
      zone: { parent: zone.parent, slot: zone.slot, index: zone.index, layout: zone.layout }
    })
  }
  function onGripDown(e) {
    if (editing || drag || selectedId === null) return
    var w = findBlock(selectedId)
    if (!w || !w.parentNode) return
    e.preventDefault() // no text selection — and no focus change, so take focus explicitly:
    // Escape must reach THIS document even when the selection came from the parent's outline.
    if (typeof window.focus === 'function') window.focus()
    var blocks = selectedIds.indexOf(selectedId) !== -1 ? selectedIds.slice() : [selectedId]
    drag = {
      session: newSession(), blocks: blocks, wrapper: w, external: false,
      ghost: null, scrollTimer: null, scrollDir: 0, zone: null, legal: null, reason: '', indicator: null
    }
    for (var bi = 0; bi < blocks.length; bi++) {
      var bw = findBlock(blocks[bi])
      if (bw) bw.classList.add('thallo-canvas-dragging')
    }
    var captureEl = e.currentTarget
    if (captureEl && captureEl.setPointerCapture && typeof e.pointerId === 'number') {
      try { captureEl.setPointerCapture(e.pointerId) } catch (err) { /* jsdom / old engines */ }
    }
    document.addEventListener('pointermove', onDragMove)
    document.addEventListener('pointerup', onDragUp)
    document.addEventListener('pointercancel', onDragCancel)
    document.addEventListener('keydown', onDragKeydown, true)
  }
  /**
   * Cursor-following drag ghost (polish batch §2): a compact, stripped clone
   * of the dragged host, built on the FIRST pointermove (a click without
   * movement must not flash one). Geometry via CSSOM transform (reworded CSP
   * pin); appearance in preview.css. Torn down in endDrag.
   */
  function buildDragGhost(w) {
    var host = firstVisualChild(w)
    if (!host) return null
    var ghostEl = document.createElement('div')
    ghostEl.className = 'thallo-canvas-drag-ghost'
    var clone = host.cloneNode(true)
    stripCanvasState(clone)
    ghostEl.appendChild(clone)
    document.body.appendChild(ghostEl)
    return ghostEl
  }
  // Edge auto-scroll (polish batch §3): one interval at a time; zone
  // membership re-evaluated per pointermove; cleared on exit and endDrag.
  var EDGE_ZONE = 48
  var EDGE_STEP = 12
  function updateEdgeScroll(clientY) {
    if (!drag) return
    var vh = window.innerHeight || 0
    var dir = 0
    if (vh > 0) {
      if (clientY < EDGE_ZONE) dir = -1
      else if (clientY > vh - EDGE_ZONE) dir = 1
    }
    if (dir === drag.scrollDir) return
    if (drag.scrollTimer) {
      clearInterval(drag.scrollTimer)
      drag.scrollTimer = null
    }
    drag.scrollDir = dir
    if (dir !== 0) {
      drag.scrollTimer = setInterval(function () {
        window.scrollBy(0, dir * EDGE_STEP)
      }, 16)
    }
  }
  function onDragMove(e) {
    if (!drag || drag.external) return
    var w = drag.wrapper
    if (!drag.ghost) drag.ghost = buildDragGhost(w)
    if (drag.ghost) {
      drag.ghost.style.transform =
        'translate(' + ((e.clientX || 0) + 12) + 'px, ' + ((e.clientY || 0) + 12) + 'px)'
    }
    updateEdgeScroll(e.clientY)
    wireZone(zoneAt(e.clientX || 0, e.clientY || 0, draggedWrappers()))
  }
  function onDragUp() {
    if (!drag) return
    if (drag.zone && drag.legal !== false) {
      post('block-drop', {
        session: drag.session,
        blocks: drag.blocks,
        zone: { parent: drag.zone.parent, slot: drag.zone.slot, index: drag.zone.index, layout: drag.zone.layout }
      })
      suppressClick = true // the click that follows a completed drag
    } else {
      post('drag-cancel', { session: drag.session })
    }
    endDrag()
  }
  function onDragCancel() {
    cancelDrag()
  }
  function onDragKeydown(e) {
    if (e.key === 'Escape' && drag) {
      e.preventDefault()
      cancelDrag()
    }
  }
  /** Cancel: the tree never changed, so only the session and its visuals end. */
  function cancelDrag() {
    if (!drag) return
    post('drag-cancel', { session: drag.session })
    endDrag()
  }
  function endDrag() {
    if (!drag) return
    removeIndicator()
    if (drag.ghost && drag.ghost.parentNode) drag.ghost.parentNode.removeChild(drag.ghost)
    if (drag.scrollTimer) clearInterval(drag.scrollTimer)
    clearClass('thallo-canvas-dragging')
    document.removeEventListener('pointermove', onDragMove)
    document.removeEventListener('pointerup', onDragUp)
    document.removeEventListener('pointercancel', onDragCancel)
    document.removeEventListener('keydown', onDragKeydown, true)
    drag = null
  }
  // Parent-originated drags (palette, outline): the parent owns the session and the pointer;
  // the stage answers hovers with proposals and paints the indicator.
  function onExternalDragBegin(data) {
    if (drag) endDrag()
    var blocks = Array.isArray(data.blocks) ? data.blocks : []
    var w = blocks.length === 1 ? findBlock(blocks[0]) : null
    drag = {
      session: data.session, blocks: blocks, wrapper: w, external: true,
      ghost: null, scrollTimer: null, scrollDir: 0, zone: null, legal: null, reason: '', indicator: null
    }
  }
  function onExternalDragHover(data) {
    if (!drag || !drag.external || drag.session !== data.session) return
    wireZone(zoneAt(data.x || 0, data.y || 0, draggedWrappers()))
  }
  function onExternalDragEnd(data) {
    if (!drag || drag.session !== data.session) return
    endDrag()
  }
  /**
   * The parent released its drag over the stage: answer with the zone under THAT point — the
   * last legality answer described an earlier hover, so it never gates this; the parent judges
   * the final zone. No zone under the point cancels. Either way the session ends here.
   */
  function onExternalDragDrop(data) {
    if (!drag || !drag.external || drag.session !== data.session) return
    var zone = zoneAt(data.x || 0, data.y || 0, draggedWrappers())
    if (zone) {
      post('block-drop', {
        session: drag.session,
        blocks: drag.blocks,
        zone: { parent: zone.parent, slot: zone.slot, index: zone.index, layout: zone.layout }
      })
    } else {
      post('drag-cancel', { session: drag.session })
    }
    endDrag()
  }
  function onDragLegality(data) {
    if (!drag || drag.session !== data.session) return
    drag.legal = data.legal === true
    drag.reason = typeof data.reason === 'string' ? data.reason : ''
    applyLegality()
  }

  // ── Stage keyboard shortcuts (keyboard-shortcuts spec §1/§2) ────────────────
  // Document-capture so theme markup can't shadow it — which is exactly why the
  // guards must be airtight: never during an edit session or drag (their own
  // handlers own Escape), never from the bridge toolbar (native button keyboard
  // semantics stay intact), never from theme form controls. Guard paths return
  // WITHOUT consuming the event — the drag's own capture handler (registered
  // later, so it runs after this one) still needs to see Escape.
  function keyTargetIsFormish(t) {
    if (!t || !t.tagName) return false
    var tag = t.tagName
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true
    if (t.isContentEditable) return true
    return !!(t.closest && t.closest('[contenteditable], input, textarea, select'))
  }

  function onCanvasKeydown(e) {
    if (selectedId === null || editing || drag) return
    var t = e.target
    if (t && t.closest && t.closest('.thallo-canvas-toolbar')) return
    if (keyTargetIsFormish(t)) return
    if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
      e.preventDefault()
      e.stopPropagation()
      post('block-move', { id: selectedId, delta: e.key === 'ArrowUp' ? -1 : 1 })
      return
    }
    if (e.key === 'Backspace' || e.key === 'Delete') {
      e.preventDefault()
      e.stopPropagation()
      post('block-delete-request', { id: selectedId }) // rect-less -> centered confirm
      return
    }
    if ((e.metaKey || e.ctrlKey) && (e.key === 'd' || e.key === 'D')) {
      e.preventDefault() // beat the browser bookmark shortcut
      e.stopPropagation()
      post('block-duplicate', { id: selectedId })
      return
    }
    if (e.key === 'Enter') {
      // Byte-equivalent to the wrapper-level double-click fallback (spec pin):
      // ONLY the block's own single region — zero, 2+, or child-owned regions
      // are not a target (review P1; same helper as the pointer path).
      var region = singleRegionOf(selectedId)
      if (!region) return
      e.preventDefault()
      e.stopPropagation()
      lastPointer = null // keyboard entry: caret placement falls back to focus()
      post('edit-request', {
        id: region.getAttribute('data-thallo-edit-block'),
        field: region.getAttribute('data-thallo-edit-field')
      })
      return
    }
    if (e.key === 'Escape') {
      e.preventDefault()
      e.stopPropagation()
      var deselectedId = selectedId
      clearSelection()
      post('block-deselect', { id: deselectedId })
    }
  }

  // ── Mirrors (stage-toolbar spec §1): DOM-only, parent-commanded ─────────────
  function stripCanvasState(root) {
    Array.prototype.forEach.call(root.querySelectorAll('.thallo-canvas-toolbar'), function (el) {
      el.parentNode.removeChild(el)
    })
    Array.prototype.forEach.call(root.querySelectorAll('.thallo-canvas-shim'), function (el) {
      el.parentNode.removeChild(el) // bridge-owned anchor shims never survive cloning
    })
    Array.prototype.forEach.call(root.querySelectorAll('.thallo-canvas-drop-line'), function (el) {
      el.parentNode.removeChild(el) // the drop indicator is drag state, never content
    })
    Array.prototype.forEach.call(root.querySelectorAll('.thallo-slot-placeholder'), function (el) {
      el.parentNode.removeChild(el) // the empty-slot placeholder is bridge UI, never content
    })
    Array.prototype.forEach.call(root.querySelectorAll('[data-thallo-slot-empty]'), function (el) {
      el.removeAttribute('data-thallo-slot-empty')
    })
    Array.prototype.forEach.call(root.querySelectorAll('[data-thallo-block-empty]'), function (el) {
      el.removeAttribute('data-thallo-block-empty')
    })
    Array.prototype.forEach.call(root.querySelectorAll('[data-thallo-empty-label]'), function (el) {
      el.removeAttribute('data-thallo-empty-label')
    })
    var classes = [
      'thallo-canvas-anchor', 'thallo-canvas-selected', 'thallo-canvas-hover',
      'thallo-canvas-selected-target', 'thallo-canvas-hover-target', 'thallo-canvas-dragging'
    ]
    classes.forEach(function (cls) {
      root.classList.remove(cls)
      Array.prototype.forEach.call(root.querySelectorAll('.' + cls), function (el) {
        el.classList.remove(cls)
      })
    })
    Array.prototype.forEach.call(root.querySelectorAll('[contenteditable]'), function (el) {
      el.removeAttribute('contenteditable')
    })
    Array.prototype.forEach.call(root.querySelectorAll('.thallo-canvas-editing'), function (el) {
      el.classList.remove('thallo-canvas-editing')
    })
  }

  // ── Partial DOM patching (dom-patching spec §2) ─────────────────────────────
  function topLevelWrappers(root) {
    // Spec pin: data-thallo-block with NO data-thallo-block ancestor.
    return Array.prototype.filter.call(
      root.querySelectorAll('[data-thallo-block]'),
      function (el) {
        return !(el.parentElement && el.parentElement.closest('[data-thallo-block]'))
      }
    )
  }

  function wrapperIds(tops) {
    return tops.map(function (el) { return el.getAttribute('data-thallo-block') })
  }

  function hasDuplicates(ids) {
    var seen = {}
    for (var i = 0; i < ids.length; i++) {
      if (seen[ids[i]]) return true
      seen[ids[i]] = true
    }
    return false
  }

  function cleanedLiveBody() {
    var clone = document.body.cloneNode(true)
    stripCanvasState(clone)
    // Body-mounted bridge UI never participates in comparisons.
    Array.prototype.forEach.call(
      clone.querySelectorAll('.thallo-canvas-format-bar, .thallo-canvas-drag-ghost'),
      function (el) { el.parentNode.removeChild(el) }
    )
    return clone
  }

  function shellSkeleton(body) {
    var clone = body.cloneNode(true)
    topLevelWrappers(clone).forEach(function (el) { el.innerHTML = '' })
    // The revision pair on <main> advances with every apply (visual builder spec §3.5): it is
    // the patch's bookkeeping, never shell drift.
    var main = clone.querySelector ? clone.querySelector('main[data-thallo-revision]') : null
    if (main) {
      main.removeAttribute('data-thallo-epoch')
      main.removeAttribute('data-thallo-revision')
      main.removeAttribute('data-thallo-style-generation')
    }
    return clone.innerHTML
  }

  // The stage now displays `pair`: remembered for the next fetch's staleness check and written
  // back to <main> so a later shell comparison and a reload both read the truth.
  function advanceDisplayed(pair) {
    if (!pair) return
    displayed = pair
    var main = document.querySelector('main[data-thallo-revision]')
    if (main) {
      main.setAttribute('data-thallo-epoch', pair.epoch)
      main.setAttribute('data-thallo-revision', String(pair.revision))
      if (typeof pair.style_generation === 'number') {
        main.setAttribute('data-thallo-style-generation', String(pair.style_generation))
      }
    }
  }

  function applyStagePatch(newBody, refreshId, fetched) {
    var liveClean = cleanedLiveBody()
    var liveTops = topLevelWrappers(liveClean)
    var newTops = topLevelWrappers(newBody)
    var liveIds = wrapperIds(liveTops)
    var newIds = wrapperIds(newTops)
    // Gate (spec pins): identical, duplicate-free id sequences. The LIVE side
    // already carries structural mirrors, so a mirror-matched order patches
    // (review P2); only unmirrored drift (add-after, disagreements) reloads.
    if (
      hasDuplicates(liveIds) || hasDuplicates(newIds)
      || liveIds.join(' ') !== newIds.join(' ')
      || (liveIds.length > 0 && newIds.length === 0)
    ) {
      post('stage-refreshed', withRevision({ refresh_id: refreshId, mode: 'reload', detail: 'id-drift' }, fetched))
      return
    }
    if (shellSkeleton(liveClean) !== shellSkeleton(newBody)) {
      post('stage-refreshed', withRevision({ refresh_id: refreshId, mode: 'reload', detail: 'shell-drift' }, fetched))
      return
    }
    var swapped = 0
    for (var i = 0; i < liveIds.length; i++) {
      if (liveTops[i].outerHTML === newTops[i].outerHTML) continue
      var liveEl = findBlock(liveIds[i]) // the REAL wrapper (ids are entry-unique)
      if (!liveEl || !liveEl.parentNode) continue
      var fresh = document.importNode(newTops[i], true)
      liveEl.parentNode.replaceChild(fresh, liveEl)
      enhanceInserted(fresh)
      swapped++
    }
    // Selection survives a swap (spec §2.6): re-anchor, or clear honestly —
    // a selected NESTED block can vanish when its swapped parent dropped it.
    if (selectedId !== null) {
      var sel = findBlock(selectedId)
      if (!sel) {
        var goneId = selectedId
        clearSelection()
        post('block-deselect', { id: goneId })
      } else if (!sel.classList.contains('thallo-canvas-selected')) {
        selectWrapper(sel)
      }
    }
    advanceDisplayed(fetched)
    markEmptySlots()
    post('stage-refreshed', withRevision({
      refresh_id: refreshId,
      mode: 'patched',
      detail: 'swapped:' + swapped + '/' + liveIds.length
    }, fetched))
  }

  // ── Fragment swaps (visual builder spec §3.5) ───────────────────────────────
  // The parent hands over the server-rendered roots of an accepted apply. Every
  // guard answers before anything moves: the stage must know its pair, the
  // patch's baseline must be that pair, the patch's epoch the same epoch, its
  // revision newer; every target must exist, every fragment must be exactly one
  // wrapper carrying its own id, and no target may sit inside another. Then all
  // swap, the runtime enhances what came in, selection is re-anchored, and the
  // displayed pair advances. A failed guard answers fragments-failed and the
  // parent refreshes from accepted state instead.
  function onFragments(data) {
    var refreshId = typeof data.refresh_id === 'string' ? data.refresh_id : ''
    function fail(reason) {
      post('fragments-failed', { refresh_id: refreshId, reason: reason })
    }
    if (editing || drag) {
      post('stage-refreshed', { refresh_id: refreshId, mode: 'busy' })
      return
    }
    var fragments = data.fragments
    if (!fragments || typeof fragments !== 'object') return fail('shape')
    if (!displayed) return fail('baseline')
    if (data.baseline_epoch !== displayed.epoch || data.baseline_revision !== displayed.revision) {
      return fail('baseline')
    }
    if (data.epoch !== displayed.epoch) return fail('epoch')
    if (typeof data.revision !== 'number' || data.revision <= displayed.revision) {
      post('stage-refreshed', withRevision(
        { refresh_id: refreshId, mode: 'stale' },
        { epoch: data.epoch, revision: data.revision }
      ))
      return
    }
    var ids = Object.keys(fragments)
    if (ids.length === 0) return fail('empty')
    var swaps = []
    for (var i = 0; i < ids.length; i++) {
      var live = findBlock(ids[i])
      if (!live || !live.parentNode) return fail('target')
      var tpl = document.createElement('template')
      tpl.innerHTML = String(fragments[ids[i]])
      var next = tpl.content.firstElementChild
      if (!next || tpl.content.childElementCount !== 1) return fail('markup')
      if (next.getAttribute('data-thallo-block') !== ids[i]) return fail('markup')
      swaps.push({ live: live, next: next })
    }
    for (var a = 0; a < swaps.length; a++) {
      for (var b = 0; b < swaps.length; b++) {
        if (a !== b && swaps[a].live.contains(swaps[b].live)) return fail('overlap')
      }
    }
    for (var s = 0; s < swaps.length; s++) {
      var inserted = document.importNode(swaps[s].next, true)
      swaps[s].live.parentNode.replaceChild(inserted, swaps[s].live)
      enhanceInserted(inserted)
    }
    if (selectedId !== null) {
      var sel = findBlock(selectedId)
      if (!sel) {
        var goneId = selectedId
        clearSelection()
        post('block-deselect', { id: goneId })
      } else if (!sel.classList.contains('thallo-canvas-selected')) {
        selectWrapper(sel)
      }
    }
    var next = { epoch: data.epoch, revision: data.revision }
    if (typeof data.style_generation === 'number') next.style_generation = data.style_generation
    advanceDisplayed(next)
    markEmptySlots()
    post('stage-refreshed', withRevision({
      refresh_id: refreshId,
      mode: 'patched',
      detail: 'fragments:' + swaps.length
    }, displayed))
  }

  // A swapped-in wrapper is server markup: custom elements tore themselves down with the
  // old subtree, and the runtime enhances the new one (canvas-skipping modules stay no-ops).
  // Slot placeholders (visual builder spec §5.4): the page's own slots (no owning block) always
  // END in the placeholder — "the next block goes here" — while a slot inside a block carries
  // it only while empty, so it never trails every nested block on the page. An empty slot is
  // also marked data-thallo-slot-empty. Re-marked after every load, patch, swap and mirror.
  function markEmptySlots() {
    var slots = document.querySelectorAll('[data-thallo-slot]')
    for (var i = 0; i < slots.length; i++) {
      var slot = slots[i]
      var placeholder = slot.querySelector(':scope > .thallo-slot-placeholder')
      var empty = slot.querySelector('[data-thallo-block]') === null
      var owned = !!(slot.parentElement && slot.parentElement.closest('[data-thallo-block]'))
      if (empty) slot.setAttribute('data-thallo-slot-empty', '')
      else slot.removeAttribute('data-thallo-slot-empty')
      if (empty || !owned) {
        if (!placeholder) {
          slot.appendChild(buildPlaceholder(slot.getAttribute('data-thallo-slot')))
        } else if (placeholder !== slot.lastElementChild) {
          slot.appendChild(placeholder) // a mirror appended past it: the placeholder stays last
        }
      } else if (placeholder) {
        slot.removeChild(placeholder)
      }
    }
    markEmptyBlocks()
  }
  // A block that paints nothing (a feature without title, marker or description) would be
  // invisible on the canvas yet still in the document — and still validated at publish. Such a
  // wrapper is marked and its visual child labelled, so the stylesheet paints a stub. Empty means:
  // no text, no media, no controls, no nested block or slot (a container's slot placeholder
  // is content). Bridge state: stripped before any comparison or clone.
  function markEmptyBlocks() {
    var wrappers = document.querySelectorAll('[data-thallo-block]')
    for (var i = 0; i < wrappers.length; i++) {
      var w = wrappers[i]
      var host = firstVisualChild(w)
      var empty = !!host && (host.textContent || '').trim() === ''
        && !host.querySelector('img,svg,video,audio,iframe,picture,canvas,input,textarea,select,button,[data-thallo-block],[data-thallo-slot]')
      if (empty) {
        w.setAttribute('data-thallo-block-empty', '')
        host.setAttribute('data-thallo-empty-label', 'Empty ' + blockKindOf(host))
      } else {
        w.removeAttribute('data-thallo-block-empty')
        if (host) host.removeAttribute('data-thallo-empty-label')
      }
    }
  }
  /** "feature" from a theme root's thallo-block-feature class; "block" when the theme names none. */
  function blockKindOf(host) {
    var m = /(?:^|\s)thallo-block-([a-z0-9_-]+)/.exec(host.className || '')
    return m ? m[1].replace(/_/g, ' ') : 'block'
  }
  // The placeholder: a dashed frame with one + (arms the parent's Blocks tab into this slot)
  // and the drag hint. Bridge-owned, never content: stripped before any comparison or clone.
  function buildPlaceholder(slotName) {
    var box = document.createElement('div')
    box.className = 'thallo-slot-placeholder'
    var add = document.createElement('button')
    add.type = 'button'
    add.setAttribute('data-slot-add', '')
    add.setAttribute('aria-label', 'Add a block to ' + slotName)
    add.textContent = '+'
    var hint = document.createElement('span')
    hint.className = 'thallo-slot-placeholder__hint'
    hint.textContent = 'Drag a block here'
    box.appendChild(add)
    box.appendChild(hint)
    return box
  }

  function enhanceInserted(el) {
    if (window.ThalloRuntime && typeof window.ThalloRuntime.enhance === 'function') {
      try { window.ThalloRuntime.enhance(el) } catch (e) { /* a module fault never breaks the swap */ }
    }
  }

  function onStageRefresh(refreshId) {
    if (editing || drag) {
      // Never fight the user's hands (spec §3) — the parent's edit-end
      // re-arm re-applies whatever the stage missed.
      post('stage-refreshed', { refresh_id: refreshId, mode: 'busy' })
      return
    }
    if (!window.fetch || !window.DOMParser) {
      post('stage-refreshed', { refresh_id: refreshId, mode: 'reload' })
      return
    }
    // Same-origin fetch of our OWN url: the session cookie rides along and
    // the stash is behind the same token URL — a REAL render of the working
    // copy, never a client-side guess (honest-stage rule).
    window.fetch(window.location.href)
      .then(function (res) {
        // Fetch-failure rules (spec pin): non-2xx or redirected -> reload.
        if (!res || !res.ok || res.redirected) throw new Error('bad response')
        return res.text()
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(String(html), 'text/html')
        if (!doc || !doc.body) throw new Error('unparseable')
        var fetched = revisionOf(doc)
        // Never step backwards within an epoch (spec §3.5): a slower render of an older
        // revision is refused and the parent refreshes again from accepted state.
        if (fetched && displayed && fetched.epoch === displayed.epoch && fetched.revision < displayed.revision) {
          post('stage-refreshed', withRevision({ refresh_id: refreshId, mode: 'stale' }, fetched))
          return
        }
        applyStagePatch(doc.body, refreshId, fetched)
      })
      .catch(function () {
        post('stage-refreshed', { refresh_id: refreshId, mode: 'reload' })
      })
  }

  function mirrorMove(id, beforeId, afterId) {
    var w = findBlock(id)
    if (!w || !w.parentNode) return
    // Same-list-only pin: the reference wrapper must be a DOM SIBLING. A stale
    // or mismatched reference in another container must never move the block
    // across parents — ignore the mirror instead.
    if (typeof beforeId === 'string') {
      var ref = findBlock(beforeId)
      if (ref && ref.parentNode === w.parentNode) ref.parentNode.insertBefore(w, ref)
    } else if (typeof afterId === 'string') {
      var prev = findBlock(afterId)
      if (prev && prev.parentNode === w.parentNode) prev.parentNode.insertBefore(w, prev.nextSibling)
    }
    markEmptySlots()
  }

  function mirrorRemove(id) {
    var w = findBlock(id)
    if (!w) return
    if (selectedId === id) clearSelection() // detach the toolbar BEFORE the wrapper goes
    if (w.parentNode) w.parentNode.removeChild(w)
  }

  function mirrorDuplicate(sourceId, idMap) {
    var src = findBlock(sourceId)
    if (!src || !src.parentNode || !idMap) return
    var clone = src.cloneNode(true)
    stripCanvasState(clone) // the source is usually SELECTED — never clone live UI state
    var ownId = clone.getAttribute('data-thallo-block')
    if (idMap[ownId]) clone.setAttribute('data-thallo-block', idMap[ownId])
    Array.prototype.forEach.call(clone.querySelectorAll('[data-thallo-block]'), function (el) {
      var next = idMap[el.getAttribute('data-thallo-block')]
      if (next) el.setAttribute('data-thallo-block', next)
    })
    // Edit regions carry their block id too (review P1): without the rewrite a
    // duplicated prose block's region keeps the SOURCE id and edit-grant for
    // the new id can never find it until the next Apply re-renders truth.
    Array.prototype.forEach.call(clone.querySelectorAll('[data-thallo-edit-block]'), function (el) {
      var mappedEdit = idMap[el.getAttribute('data-thallo-edit-block')]
      if (mappedEdit) el.setAttribute('data-thallo-edit-block', mappedEdit)
    })
    src.parentNode.insertBefore(clone, src.nextSibling)
  }

  function activate() {
    document.addEventListener('mouseover', function (e) {
      if (drag) return
      var w = wrapperFor(e.target)
      clearClass('thallo-canvas-hover')
      if (w) {
        w.classList.add('thallo-canvas-hover')
        post('block-hover', { id: w.getAttribute('data-thallo-block') })
      }
    })
    // Double-click on a prose block asks the parent for an edit grant
    // (edit-in-place spec §3) — the bridge never decides editability itself.
    document.addEventListener('dblclick', function (e) {
      if (editing) return
      var w = wrapperFor(e.target)
      if (!w) return
      // The REGION under the double-click names the field (spec §2); a
      // wrapper-level double-click falls back to the block's single region.
      var region = e.target && e.target.closest ? e.target.closest('.thallo-edit-region') : null
      if (!region || !w.contains(region)) {
        // Wrapper-level fallback: ONLY the block's own single region (review
        // P1) — shared with keyboard Enter so the two paths stay aligned.
        region = singleRegionOf(w.getAttribute('data-thallo-block'))
      }
      if (!region) return
      e.preventDefault()
      lastPointer = { x: e.clientX, y: e.clientY }
      post('edit-request', {
        id: region.getAttribute('data-thallo-edit-block'),
        field: region.getAttribute('data-thallo-edit-field')
      })
    }, true)
    // Capture phase: block-internal links/buttons are INERT while active
    // (spec §3) — editing must not navigate the stage. Toolbar clicks are the
    // ONE branch that dispatches an intent instead of (re)selecting.
    document.addEventListener('click', function (e) {
      if (suppressClick) {
        // One-shot: the click that follows a completed drag (free-drag spec §1).
        suppressClick = false
        e.preventDefault()
        e.stopPropagation()
        return
      }
      if (editing) {
        // The formatting bubble is the ONE outside-the-region click zone that
        // acts instead of ending the session (format-bar spec §3). Only
        // [data-format] clicks dispatch actions; panel-internal clicks
        // (input, apply) are handled by the panel's own listeners and must
        // never commit-and-exit (link-panel spec §2).
        var inBar = e.target && e.target.closest
          ? e.target.closest('.thallo-canvas-format-bar')
          : null
        if (inBar) {
          var fmtBtn = e.target.closest('.thallo-canvas-format-bar [data-format]')
          if (fmtBtn) {
            e.preventDefault()
            e.stopPropagation()
            applyFormat(fmtBtn.getAttribute('data-format'))
          }
          return
        }
        // Caret placement inside the active region passes through untouched;
        // any click outside commits-and-exits, then v2 semantics resume.
        if (editing.region.contains(e.target)) return
        commitEditing()
        endEditing()
      }
      var btn = e.target && e.target.closest
        ? e.target.closest('.thallo-canvas-toolbar [data-action]')
        : null
      if (btn && selectedId !== null) {
        e.preventDefault()
        e.stopPropagation()
        var action = btn.getAttribute('data-action')
        if (action === 'drag') return // drags are pointer-driven, never a click intent
        if (action === 'move-up') post('block-move', { id: selectedId, delta: -1 })
        if (action === 'move-down') post('block-move', { id: selectedId, delta: 1 })
        if (action === 'duplicate') post('block-duplicate', { id: selectedId })
        if (action === 'delete') {
          // Anchor for the parent's confirm (same mechanism as add-after).
          var dr = btn.getBoundingClientRect()
          post('block-delete-request', { id: selectedId, rect: { x: dr.left, y: dr.bottom } })
        }
        if (action === 'add-after') {
          // The Blocks tab arms "after this block" (Phase C.1): no anchor needed.
          post('block-add-after', { id: selectedId })
        }
        return
      }
      // A tabs label: the runtime's tabs module skips the canvas, so the CSS radio floor drives
      // the panels — let the click reach its radio (every other in-block click is inert), then
      // select the tab block that panel shows, so the inspector edits the tab just switched to.
      var tabLabel = e.target && e.target.closest ? e.target.closest('label.thallo-block-tabs__label[for]') : null
      if (tabLabel) {
        e.preventDefault()
        e.stopPropagation()
        var radio = document.getElementById(tabLabel.getAttribute('for'))
        if (radio) radio.checked = true
        var tabsRoot = tabLabel.closest('.thallo-block-tabs')
        var labels = tabsRoot ? Array.prototype.slice.call(tabsRoot.querySelectorAll(':scope > .thallo-block-tabs__list > .thallo-block-tabs__label')) : []
        var panels = tabsRoot ? Array.prototype.slice.call(tabsRoot.querySelectorAll(':scope > .thallo-block-tabs__panels > .thallo-block-tabs__panel')) : []
        var panel = panels[labels.indexOf(tabLabel)]
        var tabWrapper = panel ? panel.querySelector('[data-thallo-block]') : null
        var target = tabWrapper || wrapperFor(tabLabel)
        if (target) {
          selectWrapper(target)
          post('block-select', { id: target.getAttribute('data-thallo-block'), shift: false, meta: false })
        }
        return
      }
      // The empty-slot + asks the parent to arm its Blocks tab into that slot (never a selection).
      var addBtn = e.target && e.target.closest ? e.target.closest('[data-thallo-slot] [data-slot-add]') : null
      if (addBtn) {
        e.preventDefault()
        e.stopPropagation()
        var slotEl = addBtn.closest('[data-thallo-slot]')
        var ownerEl = slotEl.parentElement ? slotEl.parentElement.closest('[data-thallo-block]') : null
        post('slot-add', {
          parent: ownerEl ? ownerEl.getAttribute('data-thallo-block') : null,
          slot: slotEl.getAttribute('data-thallo-slot')
        })
        return
      }
      var w = wrapperFor(e.target)
      if (!w) return
      e.preventDefault()
      e.stopPropagation()
      selectWrapper(w)
      post('block-select', {
        id: w.getAttribute('data-thallo-block'),
        shift: !!e.shiftKey,
        meta: !!(e.metaKey || e.ctrlKey)
      })
    }, true)
    document.addEventListener('keydown', onCanvasKeydown, true)
    // Scroll preservation (auto-apply spec §3): trailing-throttled reports;
    // the parent restores after every stage reload.
    var scrollTimer = null
    window.addEventListener('scroll', function () {
      if (scrollTimer) return
      scrollTimer = setTimeout(function () {
        scrollTimer = null
        post('scroll', { y: window.scrollY || 0 })
      }, 250)
    })
    markEmptySlots()
    post('blocks-index', { ids: idsIndex() })
  }

  window.addEventListener('message', function (event) {
    var data = event.data || {}
    if (!session) {
      if (data.type === 'thallo:canvas-hello' && typeof data.nonce === 'string') {
        session = { origin: event.origin, nonce: data.nonce }
        activate()
      }
      return
    }
    if (event.origin !== session.origin || data.nonce !== session.nonce) return
    if (data.type === 'thallo:highlight') {
      // Outline-driven selection behaves like a stage click: ring + toolbar.
      var el = findBlock(data.id)
      if (el) selectWrapper(el)
      else clearSelection()
      if (el && Array.isArray(data.ids)) ringSelection(data.ids)
    }
    if (data.type === 'thallo:scroll-to') {
      var t = findBlock(data.id)
      if (t && t.firstElementChild) {
        t.firstElementChild.scrollIntoView({ block: 'center', behavior: 'smooth' })
      }
    }
    if (
      data.type === 'thallo:edit-grant' && typeof data.id === 'string'
      && typeof data.field === 'string' && typeof data.kind === 'string'
    ) {
      startEditing(data.id, data.field, data.kind)
    }
    if (data.type === 'thallo:edit-flush') {
      if (editing) {
        commitEditing()
        endEditing()
      }
      post('edit-flushed') // ALWAYS ack (spec §3) — the parent awaits this
    }
    if (data.type === 'thallo:restore-scroll' && typeof data.y === 'number') {
      window.scrollTo(0, data.y) // instant — a reload restore must not visibly travel
    }
    if (data.type === 'thallo:drag-begin' && typeof data.session === 'string') onExternalDragBegin(data)
    if (data.type === 'thallo:drag-hover' && typeof data.session === 'string') onExternalDragHover(data)
    if (data.type === 'thallo:drag-legality' && typeof data.session === 'string') onDragLegality(data)
    if (data.type === 'thallo:drag-drop' && typeof data.session === 'string') onExternalDragDrop(data)
    if (data.type === 'thallo:drag-end' && typeof data.session === 'string') onExternalDragEnd(data)
    if (data.type === 'thallo:stage-refresh') {
      onStageRefresh(typeof data.refresh_id === 'string' ? data.refresh_id : '')
    }
    if (data.type === 'thallo:fragments') onFragments(data)
    if (data.type === 'thallo:mirror-move') mirrorMove(data.id, data.beforeId, data.afterId)
    if (data.type === 'thallo:mirror-remove') mirrorRemove(data.id)
    if (data.type === 'thallo:mirror-duplicate') mirrorDuplicate(data.sourceId, data.idMap)
  })
})()
