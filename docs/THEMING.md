# Thallo Theming Guide

How to build and style a Thallo theme: the folder layout, the page templates,
the region system, the block data contract, and the **hand-authored BEM styling
convention** used for blocks. Examples use the real variables Thallo passes to
templates.

> Status: working guide. The shipped `default` theme styles every block with
> hand-authored `thallo-block-{slug}` BEM classes in `assets/blocks.css` (plus
> `navigation.css` / `stepper.css`), authored against the theme tokens — no build
> step, no Tailwind at runtime. The editor and engine don't depend on the
> presentational classes (see *Editing hooks*), so they're yours to restyle.

---

## 1. What a theme is

A theme is **just a folder** — no build step is required to *use* one.

```
themes/<name>/
  theme.json          # manifest: name, version, menus
  templates/          # Twig: page templates + blocks/
  assets/             # site.css, blocks.css, blocks.js (+ images, fonts…)
```

**Where themes live**
- **Pack default** (fallback, shipped with the render package):
  `packages/thallo-render/themes/default/`
- **A site's own theme** (app-level): `<app-root>/themes/<name>/`

**Activation** — one of:
- Admin → **General settings → Theme**, or
- `.env` → `RENDER_THEME=<name>`

**Per-template fallback:** if the active theme is missing a specific template,
the engine falls back to the default theme's copy of that file. So a theme only
needs to ship the files it actually changes.

`theme.json`:
```json
{ "name": "my-theme", "version": "1.0.0", "menus": ["main"] }
```

---

## 2. Page templates

Files in `templates/`. Each page template typically `{% extends 'layout.twig' %}`
and fills `{% block content %}`.

| File | Renders |
|------|---------|
| `layout.twig` | The HTML shell: `<head>`, header/footer **regions**, and `{% block content %}`. Every page template extends this. |
| `index.twig` | The site homepage (`/`). |
| `entry.twig` | A single content entry (e.g. `/blog/my-post`). |
| `listing.twig` | A type's index/listing page (e.g. `/blog`). |
| `archive.twig` | A taxonomy archive (e.g. `/blog/categories/news`). |
| `terms.twig` | A taxonomy term index. |
| `404.twig` | Not-found page. |
| `error.twig` | Generic error page. |
| `_pagination.twig` | Shared pagination partial (path-based: `/blog/page/2`). |
| `region-preview.twig` | Isolated render of a single region (used by the region editor). |

`layout.twig` loads the assets and enhancement script:
```twig
<link rel="stylesheet" href="{{ asset('site.css') }}">
<link rel="stylesheet" href="{{ asset('blocks.css') }}">
<script defer src="{{ asset('blocks.js') }}"></script>
```

---

## 3. Regions

Regions are **global chrome** rendered around every page. There are two:
`header` and `footer`.

- `region_blocks('header')` → HTML for the saved region (its blocks), or `null`
  when nothing is bound. Fall back to hardcoded chrome on `null`.
- `region_settings('header')` → the region's settings (e.g. `width`, `sticky`).
- A page can hide a region: `presentation.header == 'hidden'`.

Pattern from `layout.twig`:
```twig
{% set headerHidden = (presentation.header|default('default')) == 'hidden' %}
{% set headerHtml = headerHidden ? null : region_blocks('header') %}
{% if headerHtml %}
  {% set hs = region_settings('header') %}
  <header class="site-header … {{ hs.sticky|default(false) ? 'is-sticky' : '' }}">
    <div class="site-header__inner">{{ headerHtml }}</div>
  </header>
{% elseif not headerHidden %}
  {# hardcoded fallback header: logo + menu('main') #}
{% endif %}
```

Never render an *empty* region — a `null` (unbound reader, absent row, or
saved-empty list) must fall back to the built-in chrome. Hiding is a page
`presentation` decision, not an empty region.

---

## 4. Blocks

Every block type has one template at `templates/blocks/<type>.twig`.

### 4.1 How a block receives data

A block template is rendered with a **`data`** object holding that block's
fields, plus the theme helper functions. Example — the real `hero` block reads
`data.title`, `data.headline`, `data.description`, `data.image`, `data.links`,
`data.orientation`, `data.reverse`.

Access fields with `data.<field>` and always provide a `|default(...)` for
optional ones.

### 4.2 Helpers available in templates

Functions:
- `asset('blocks.css')` — URL to a theme asset.
- `media(uuid, variant?)` — resolve an asset UUID to a servable URL (`null` if not servable).
- `blocks(list)` — render a list of **child blocks** (nesting; e.g. hero links, carousel slides).
- `icon(name)` — render an icon.
- `menu('main')` — items for a named menu.
- `region_blocks(name)` / `region_settings(name)` — region HTML / settings.
- `site_logo(variant?)`, `site_favicon()`, `custom_css()` — site identity.
- `path(...)`, `facets(...)`, `video_embed(...)`.

Filters:
- `|editable_text('field')` — **use this on editable text.** It emits the
  in-place editing hooks (`data-thallo-edit-field`) so the field is editable on
  the canvas. Plain `{{ data.title }}` renders but isn't editable.
- `|safe_html` — sanitize + emit author-authored rich HTML (rich-text / `body`).
- `|safe_url` — sanitize a URL attribute.

### 4.3 Editing hooks (why you're free to restyle)

- The **render engine** wraps each block with `data-thallo-block` on the canvas —
  the theme does **not** add that. So the editor finds blocks by data-attribute,
  **not** by the `thallo-block-*` CSS classes.
- In-place text editing comes from the `|editable_text('field')` filter, not from
  a class.

**Consequence:** presentational classes are yours to change freely. The only
classes/attributes you must keep stable are ones your **own `blocks.js`** selects
(see the carousel example).

### 4.4 The block set

`accordion` · `accordion_item` · `audio` · `button` · `card` · `carousel` ·
`collapsible` · `columns` · `container` · `cta` · `feature` · `file` · `footer` ·
`grid` · `heading` · `hero` · `html` · `icon` · `image` · `links` ·
`logo` · `logos` · `navigation` · `rich_text` · `section` · `separator` ·
`shortcode` · `social_link` · `social_links` · `spacer` · `stepper` ·
`stepper_item` · `tab` · `tabs` · `video`

`accordion_item` and `stepper_item` are **item carriers**: their parent
(`accordion` / `stepper`) renders them inline from `item.data.*`, and they also
have a standalone template for when one is dropped on its own. The single source
of truth for this set is `app/Content/Blocks/StarterBlockTypes.php` (schema) — the
template set mirrors it one-to-one.

---

## 5. Styling convention (hand-authored BEM in Twig)

Blocks are styled with **hand-authored CSS in our own class namespace**, written
against the theme's design tokens and shipped as a plain `assets/blocks.css`
(large or interactive blocks get a dedicated stylesheet — `navigation.css`,
`stepper.css`). There is **no build step and no toolchain** — you edit CSS by
hand and the browser loads it.

**The class namespace is BEM under `thallo-block-{slug}`:**
- root: `thallo-block thallo-block-{slug}`
- elements: `thallo-block-{slug}__{element}` (e.g. `__inner`, `__title`, `__links`)
- modifiers: `thallo-block-{slug}--{modifier}` (e.g. `--vertical`, `--reverse`)

**Build the class strings up top in guarded `{% set %}` maps** — one derivation
site per block, each enum guarded with `?? 'default'` so an unknown stored value
degrades to the default modifier instead of emitting a class no CSS matches:

```twig
{% set orientation = data.orientation|default('vertical') %}
{% set rootClass = [
  'thallo-block thallo-block-hero',
  'thallo-block-hero--' ~ ({vertical: 'vertical', horizontal: 'horizontal'}[orientation] ?? 'vertical'),
  data.reverse|default(false) ? 'thallo-block-hero--reverse' : '',
]|join(' ')|trim %}
```

**Multi-value settings → a map to modifier classes** (keep the fallback literal):
```twig
{% set colsMod = {
  '2':'thallo-block-grid--cols-2', '3':'thallo-block-grid--cols-3', '4':'thallo-block-grid--cols-4'
}[data.columns|default('3')] ?? 'thallo-block-grid--cols-3' %}
```

**Freeform settings (arbitrary color / spacing you can't enumerate) → inline CSS
variables** the hand-authored CSS then consumes:
```twig
<div class="thallo-block thallo-block-container" style="--container-bg: {{ data.background_color }}">
```
```css
.thallo-block-container { background-color: var(--container-bg, transparent); }
```

**Author content (`rich_text` / `body`) → the `thallo-block-rich_text` measure**,
styled by hand in `blocks.css`; never wrap it in utility classes:
```twig
<div class="thallo-block thallo-block-rich_text">{{ data.body|safe_html }}</div>
```

> **`tw-class.css` is design REFERENCE only** — a compiled dump of Nuxt UI /
> Tailwind utilities to read a recipe off, never shipped or linked. Do **not**
> put raw Tailwind utility classes (`grid-cols-2`, `flex`, `size-8`, `text-muted`,
> `prose`) in a template — they render unstyled because nothing ships them.
> Translate the design you want into hand-authored rules under our BEM classes,
> in our tokens.

**Design tokens** (defined once at the top of `blocks.css`, and doubling as the
per-site re-skinning surface): `--accent`, `--ink`, `--muted`, `--surface`,
`--surface-2`, `--line`, `--bg`, `--accent-ink`, `--shadow`, `--radius`,
`--radius-lg`, `--container`, `--content`, `--space-1` … `--space-7`.

---

## 6. Examples

### 6.1 `hero` — the reference block (BEM markup, hand-authored CSS)

```twig
{# Fields: headline, title, description, links (nested button blocks),
   image (asset → media()), orientation (vertical|horizontal), reverse. #}
{% set img = data.image ? media(data.image) : null %}
{% set orientation = data.orientation|default('vertical') %}
{% set reverse = data.reverse|default(false) %}
{% set rootClass = [
  'thallo-block thallo-block-hero',
  'thallo-block-hero--' ~ ({vertical: 'vertical', horizontal: 'horizontal'}[orientation] ?? 'vertical'),
  reverse ? 'thallo-block-hero--reverse' : '',
]|join(' ')|trim %}

<header class="{{ rootClass }}">
  <div class="thallo-block-hero__inner">
    <div class="thallo-block-hero__wrapper">
      {% if data.headline %}<p class="thallo-block-hero__headline">{{ data.headline|editable_text('headline') }}</p>{% endif %}
      <h1 class="thallo-block-hero__title">{{ data.title|editable_text('title') }}</h1>
      {% if data.description %}<p class="thallo-block-hero__description">{{ data.description|editable_text('description') }}</p>{% endif %}
      {% if data.links|default([]) is not empty %}<div class="thallo-block-hero__links">{{ blocks(data.links) }}</div>{% endif %}
    </div>
    {% if img %}<div class="thallo-block-hero__media"><img src="{{ img }}" alt=""></div>{% endif %}
  </div>
</header>
```

The matching `thallo-block-hero*` rules live in `assets/blocks.css` (orientation,
`--reverse`, `__title`/`__description` type scale) — all in tokens, so a per-site
re-skin only re-maps the token values.

Notes:
- Text fields use `|editable_text('field')` → still editable on the canvas.
- `media(data.image)` resolves the asset; `blocks(data.links)` renders the nested
  link blocks.
- The `thallo-block-hero*` classes are the styling surface AND a stable hook for
  overrides — but they are **not** how the editor finds blocks (that's the
  `data-thallo-block` wrapper the engine adds).

### 6.2 `carousel` — a JS-driven block (keep the hooks)

The theme's `blocks.js` enhances carousels by selecting
`.thallo-block-carousel`, `.thallo-block-carousel__viewport`, and
`.thallo-block-carousel__track`, and reads `data-arrows` / `data-dots` /
`data-autoplay`. **These are behavior hooks — keep them**; all looks come from the
hand-authored `.thallo-block-carousel*` rules in `blocks.css`.

```twig
<div
  class="thallo-block thallo-block-carousel"
  data-arrows="{{ data.arrows|default(false) ? '1' : '0' }}"
  data-dots="{{ data.dots|default(false) ? '1' : '0' }}"
  data-autoplay="{{ data.autoplay|default(false) ? '1' : '0' }}"
>
  <div class="thallo-block-carousel__viewport">
    <div class="thallo-block-carousel__track">{{ blocks(data.slides) }}</div>
  </div>
</div>
```

(`slides_per_view` drives a modifier via a guarded map, e.g.
`{'1':'', '2':'thallo-block-carousel--per-2', '3':'thallo-block-carousel--per-3'}[data.slides_per_view] ?? ''`,
with the per-view basis set in CSS.)

---

## 7. Editing `blocks.css` (no build, no toolchain)

`assets/blocks.css` is **hand-authored and committed** — there is no compile step.
Add or change a block's rules directly, under its `thallo-block-{slug}` BEM
selectors, using the theme tokens.

- **Tokens up top:** the `:root` block at the head of `site.css` defines
  `--accent`, `--ink`, `--muted`, `--surface`, `--line`, `--space-*`, `--radius`,
  `--container`, `--content`, etc. Author every rule (in `blocks.css` and every
  other stylesheet) against these — a per-site re-skin only re-maps the token
  values. Dark mode re-maps the same tokens under `html[data-theme="dark"]`
  (see §8).
- **Large / interactive blocks get their own file** loaded alongside `blocks.css`
  in `layout.twig` and `region-preview.twig` (`navigation.css`, `stepper.css`).
  Wire a new one with a `<link rel="stylesheet" href="{{ asset('your.css') }}">`.
- **Interactive disclosure blocks** (`accordion`, `collapsible`) are native
  `<details>` — CSS-only, no JS. Only reach for `blocks.js` when a block genuinely
  needs scripting (e.g. `carousel`).
- Consumers get plain `.css` files — no build, no toolchain, nothing to run.

## 8. Color mode (light / dark / system)

Visitors can choose **light**, **dark**, or **system** (follow the OS). The
rendered HTML stays mode-agnostic — the choice lives in the browser, never in
the markup — so a single cached page serves every mode.

### 8.1 The `data-theme` contract

A tiny inline script in `<head>` (the *no-flash resolver*) reads the stored
preference from `localStorage['thallo.colorMode']` and stamps
`html[data-theme="light"]` or `html[data-theme="dark"]` **before the CSS loads**,
so there is no flash of the wrong theme. `data-theme` is only ever `light` or
`dark` — `system` is resolved to one of the two against the OS preference; it is
never written to the attribute.

**All theme CSS keys off `data-theme`, not the OS media query.** This is the
single source of truth: an explicit *light* choice stays light even on an
OS-dark machine. (A visitor with JavaScript disabled therefore always gets
light — the accepted, uncommon degraded state.)

### 8.2 The dark token re-map

Dark mode is a **token re-map**, nothing more. `site.css` defines the light
tokens under `:root` and re-maps them under `html[data-theme="dark"]`:

```css
:root { --bg: #ffffff; --ink: #0f172a; /* … */ }
html[data-theme="dark"] { --bg: #0b1120; --ink: #e2e8f0; /* … */ }
```

Because every block paints from `var(--…)` (§7), the whole theme flips with no
per-block dark rules. A per-site re-skin re-maps **both** blocks — set your
brand values in `:root` and your dark values under `html[data-theme="dark"]`.
Do **not** re-introduce `@media (prefers-color-scheme: dark)` on `:root`: it
would override an explicit light choice on an OS-dark machine.

### 8.3 The toggle block

Drop the **Color mode** block (`color_mode`) into a region (it's in the header
palette) to give visitors a light / system / dark switch. It renders a
three-option segmented control; each option carries `data-color-mode-set`
(`light` | `system` | `dark`). The runtime in `blocks.js` wires the clicks,
persists the choice, updates `data-theme`, reflects the active option
(`aria-checked`), and dispatches a `thallo:color-mode-change` event on
`<html>`. `window.thalloColorMode` (`get()` / `set()` / `resolved()`) is
available for custom controls.

### 8.4 Turning it off

Set `THALLO_COLOR_MODE_ENABLED=false` (config `theme.color_mode.enabled`). With
color mode **off**: no resolver script and no `data-color-mode-enabled` marker
are emitted, the `blocks.js` runtime stays inert (even if `localStorage` still
holds a stale `dark`), and the `color_mode` block renders nothing. The site
falls back to the light tokens.

### 8.5 Content-Security-Policy

The resolver is the **only** inline script, and it is byte-stable. If you run a
strict CSP, allow it by **hash** (no `unsafe-inline`, no per-request nonce that
would break page caching). Add to `script-src`:

```
script-src 'sha256-LPPpGD9ammrw92nJUwoMRPu1xnHk26P8c3tFKYUe8OE='
```

The digest is published as `Thallo\Render\ColorMode::RESOLVER_SHA256`
(`base64(sha256(RESOLVER_JS))`); a test fails the build if the script bytes ever
drift from it, so this value stays correct. Glueful ships no CSP by default
(`CSP_HEADER` is unset) — this only matters if you opt into one.

## 9. Theme colors (accent + neutral)

An operator can re-skin the theme by choosing a brand **accent** and a
**neutral** tone from **Settings → General → Theme colors**. It re-maps the
design **tokens only** — it never swaps a template — and applies in both light
and dark mode.

### 9.1 What's configurable

- **Accent** — one Tailwind hue family: `red, orange, amber, yellow, lime,
  green, emerald, teal, cyan, sky, blue, indigo, violet, purple, fuchsia, pink,
  rose`.
- **Neutral** — one Tailwind neutral family: `slate, gray, zinc, neutral, stone`.
- **Defaults `blue` / `slate`** reproduce the shipped look exactly.

Both are closed enums; a save `422`s on anything else. Stored in `GeneralSettings`
as `theme_accent` / `theme_neutral`.

### 9.2 How it re-skins (tokens only)

Each family maps to concrete token values (light + dark) via a curated table
(`Thallo\Render\Theme\ThemeColors`). A `theme_colors_style()` function emits a
`:root { … }` + `html[data-theme="dark"] { … }` override in `<head>`, **after
`site.css`/`blocks.css` and before `custom.css`** so custom CSS stays the final
escape hatch. Because every block paints from `var(--…)`, the whole theme flips
with no per-block rules — and the dark accent now comes from the chosen family
(replacing the old hard-coded blue).

**The default emits nothing.** `blue`/`slate` lives canonically in `site.css`, so
a default site's HTML stays override-free; only a non-default pair emits a style.

### 9.3 Preview before apply

The card's **Preview on site** mints a preview session carrying the *pending*
(unsaved) pair and opens the live-rendered site. The chosen colors are **signed
into the preview token** and applied for that session only — they are never
written to settings until you **Save**. Exiting/expiring the preview reverts to
the saved pair with no residue.

### 9.4 Caching

The render page cache (and the fixed 404/410 bodies) key on the resolved pair —
`render:{theme}:{accent}-{neutral}:{path}` — and a save dispatches
`ThemeAppearanceChanged`, which purges `thallo:render:page`. A color change is
reflected immediately, and a bad stored value falls back to `blue`/`slate` (and
logs) rather than emitting broken CSS.

### 9.5 Content-Security-Policy

The generated `<style>` **varies by settings**, so — unlike the color-mode
resolver — a static hash can't cover it, and a cache-safe page can't carry a
per-request nonce. If you run a strict CSP, allow inline styles:

```
style-src 'unsafe-inline'
```

This is acceptable because the style is generated from **two closed enums**, not
free CSS (a far narrower trust surface than `custom.css`), and Glueful ships no
CSP by default. If strict-CSP perfection is later required, the same storage +
token model can serve the CSS from a linked `/theme-colors.css` route instead —
a delivery-only change.

## 10. Style block (scoped accent/neutral + class hook)

The **Style** block (`slug: style`, category Layout) re-skins a group of blocks
without swapping templates — the local sibling of the global theme color config (§9).

### 10.1 What it configures
- **Accent** and **Neutral** — the same closed Tailwind families as §9. Each is
  optional; the first option, **Inherit**, leaves that dimension unchanged.
- **Class hook** (`class_hook`) — an optional custom-CSS hook (see §10.4).
- **Content** — the child blocks the skin applies to.

### 10.2 How it re-skins (tokens only, follows color mode)
The block redefines design-token custom properties (`--accent`/`--accent-ink` for
accent; `--bg`/`--surface`/`--surface-2`/`--ink`/`--muted`/`--line` for neutral) on
its subtree via a generated scope class `thallo-skin-{accent}-{neutral}` (an unset
dimension is `none`, e.g. `thallo-skin-rose-none`). It **follows the global light/dark
mode** (§ color-mode): the emitted `<style>` carries both a light rule and an
`html[data-theme="dark"] …` rule, so the reader's chosen mode still wins. Only the
set dimension's variables are emitted; picking **Inherit** (or leaving a dimension
blank) emits nothing for it. An unknown/stale value is treated as inherit — a scoped
block has a safe do-nothing state, so it never falls back to the global blue/slate.

### 10.3 Delivery
Each Style block emits its own small `<style>` next to its wrapper (not hoisted to
`<head>`), so the block fragment stays self-contained for the visual canvas. Identical
accent/neutral pairs share one deterministic scope class. As with §9, the inline
`<style>` relies on the CSP `style-src 'unsafe-inline'` allowance (accepted for v1).

### 10.4 Custom class hook
The **Class hook** field lets you target the wrapper from `custom.css`. Enter a bare
hook name (e.g. `promo`); it renders as the namespaced class `thallo-style-promo` on
the wrapper. Multiple space-separated hooks are allowed. Input is sanitized at render
time — only safe class tokens survive — so it can never inject markup.

### 10.5 Preview & caching (inherited, no new machinery)
Style values are ordinary published block content, so they preview through the normal
content preview and their rendered HTML is invalidated by the existing content/publish
cache purge (the render entry is tagged with the page's entry surrogate). There is no
separate preview token, appearance fingerprint, or purge listener for this block.

## 11. Shadows (elevation scale + block controls)

The theme ships a Tailwind-derived elevation scale as design tokens in `site.css`,
light + dark aware, plus page-builder controls on the Style and Container blocks.

### 11.1 The scale
`--shadow-none`, `--shadow-2xs`, `--shadow-xs`, `--shadow-sm`, `--shadow-md`,
`--shadow-lg`, `--shadow-xl`, `--shadow-2xl`. `--shadow` aliases `--shadow-md` (the
default), so every component that used the old flat shadow now renders md; floating
overlays (nav dropdown) use `--shadow-lg`. Apply a depth anywhere with the utility
classes `.thallo-shadow-{level}`.

### 11.2 Overridable color + opacity
Each token composes its color from `--shadow-color` and its opacity from
`calc(<base>% * --shadow-strength)` via `color-mix()`. Defaults: light slate-900 /
strength 1; dark black / strength 2.5 (the scale recomputes automatically in dark —
no separate dark shadow values). Override either variable on an element for a colored
or stronger/softer shadow.

### 11.3 Block controls
- **Style block:** `shadow` (depth), `shadow_color` (any hex — the "colored shadow"),
  `shadow_opacity` (0–200, where 100 = as-designed — the "opacity modifier"),
  `padding` (all sides) and `margin` (vertical). Color/opacity are emitted as inline
  `--shadow-color` / `--shadow-strength` on the wrapper, and are only applied when they
  pass a render-time shape/range guard. All default to `none`/unset.
- **Container:** `shadow` (depth) only. Defaults to `none`.
