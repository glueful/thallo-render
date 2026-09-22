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
  theme.json          # manifest: name, version, menus, its gallery card
  screenshot.jpg      # optional: the theme's picture in the admin's gallery
  templates/          # Twig: page templates + blocks/
  assets/             # site.css, blocks.css (+ images, fonts…); behaviour is the package's
```

**Where themes live**
- **Pack default** (fallback, shipped with the render package):
  `packages/thallo-render/themes/default/`
- **A site's own theme** (app-level): `<app-root>/themes/<name>/`

**Every entry template gets its `type`.** `entry.twig`, `entry/{type}.twig`, `listing.twig` and
`listing/{type}.twig` all receive the content type's slug as `type`, so a template that
navigates its type — `entry_tree(type)` — need not be named after one.

**Activation** — one of:
- Admin → **Site › Appearance → Theme**, or
- `.env` → `RENDER_THEME=<name>`

**Per-template fallback:** if the active theme is missing a specific template,
the engine falls back to the default theme's copy of that file. So a theme only
needs to ship the files it actually changes.

`theme.json`:
```json
{
  "name": "my-theme",
  "version": "1.0.0",
  "vocabulary": { "spacing.none": "0", "spacing.xs": "var(--space-1)", "…": "…" },
  "stylesheets": ["assets/site.css", "assets/blocks.css"]
}
```

`vocabulary` maps every name of the platform style vocabulary to a CSS value and
`stylesheets` lists the theme's CSS in load order — both are required, and a theme
missing either fails at load, on switch and in `thallo:doctor` (see §12).

**The gallery card.** Site › Appearance shows every selectable theme as a card, and these
optional `theme.json` keys are what the card says:

```json
{
  "title": "Aurora",
  "description": "A bright theme for studios and portfolios.",
  "author": "Studio North",
  "tags": ["portfolio", "light and dark"],
  "screenshot": "screenshot.jpg",
  "colors": { "background": "#0b1020", "text": "#ffffff", "accent": "#7c3aed" }
}
```

| Key | What it is |
|---|---|
| `title` | The name shown on the card. Without it, the folder's name. |
| `description` | Up to 300 characters. |
| `author` | Who made it. |
| `tags` | Up to six short words. |
| `screenshot` | An image inside the theme's folder: `png`, `jpg` or `webp`, 2 MB at most. A `screenshot.jpg` (or `.png`, `.webp`) at the theme's root is found without being named. 4:3 is the card's shape; 1200×900 is what Thallo ships. |
| `colors` | Hex colours for the thumbnail the admin **draws** when there is no screenshot, so a card is never blank. |

None of it is required and none of it can break a theme: a wrong value is left off the card,
never an error. Duplicating a theme resets its title, author and tags, says where it came
from, and keeps the screenshot, which is true until you change the look. The site serves the
screenshot at `/_thallo/theme-screenshot/<name>` — that one file and nothing else of a theme.

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

`blog` in these paths stands for any content type you create; Thallo ships no `blog` type.

`layout.twig` links exactly three stylesheets — the layer order sheet, the theme
artifact (every manifest stylesheet plus every package-contributed sheet, inside
`@layer theme`) and the compiled settings artifact (`@layer settings`) — never the
theme's files one by one (see §12). The default layout's `<head>`, abbreviated:
```twig
{{ color_mode_script() }}
<title>{% block title %}{{ seo.title|default(site.name) }}{% endblock %}</title>
{{ seo_head() }}
{% set favicon = site_favicon() %}
{% if favicon %}<link rel="icon" href="{{ favicon }}">{% endif %}
{{ font_faces_style('Figtree', 'fonts/figtree-roman-latin.woff2', 'fonts/figtree-italic-latin.woff2') }}
<link rel="stylesheet" href="{{ layers_stylesheet_url() }}">
<link rel="stylesheet" href="{{ theme_stylesheet_url() }}">
<link rel="stylesheet" href="{{ settings_stylesheet_url() }}">
{{ theme_colors_style() }}
{% set customCss = custom_css() %}
{% if customCss %}<link rel="stylesheet" href="{{ customCss }}">{% endif %}
<script defer src="{{ runtime_script() }}"></script>
```

`color_mode_script()` comes before any CSS so `data-theme` is set before the first paint.
`custom_css()` loads last so the site's own rules win. `runtime_script()` is the package's
theme runtime (carousel, tabs, navigation, colour mode, forms); keep it in a copied layout.

---

## 3. Regions

Regions are **global chrome** rendered around every page. There are two:
`header` and `footer`.

- `region_blocks('header')` → HTML for the saved region (its blocks), or `null`
  when nothing is bound. Fall back to hardcoded chrome on `null`.
- `region_settings('header')` → the region's settings: `width` (`contained` or `full`), `sticky` (header only)
  and `style`, the Style tab's saved settings, which `region_style_classes()` reads for you.
- `region_style_classes('header')` → the utility classes for the region's own style (the Regions
  page's Style tab), with a leading space; `''` when the region is unstyled. The second argument
  names the target: `'root'` (the default — the bar: margins, colours, background opacity,
  backdrop blur, border, corners, shadow) or `'inner'` (padding, because that is where a theme
  pads). Put each on its element:

  ```twig
  <header class="site-header …{{ region_style_classes('header') }}">
    <div class="site-header__inner{{ region_style_classes('header', 'inner') }}">{{ headerHtml }}</div>
  </header>
  ```

  A theme whose layout omits them still works; its header and footer just ignore the Style tab.
- Name the colour each bar is painted with: `--t-surface-default` on the element, beside its
  `background`. **Background opacity** with no colour chosen mixes that colour; without the
  variable it has nothing to mix and paints the bar transparent.

  ```css
  .site-header { --t-surface-default: var(--bg); background: var(--bg); }
  ```
- A page can hide a region: `presentation.header == 'hidden'`.

Pattern from `layout.twig`:
```twig
{% set headerHidden = (presentation.header|default('default')) == 'hidden' %}
{% set headerHtml = headerHidden ? null : region_blocks('header') %}
{% if headerHtml %}
  {% set hs = region_settings('header') %}
  <header class="site-header … {{ hs.sticky|default(false) ? 'thallo-region-header--sticky' : '' }}">
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

Every function and filter below is registered on every render; the ones that need a package
or a setting return `null` or an empty list without it. [Template
functions](../../../docs/reference/03-template-functions.md) has each one's return shape and an
example.

Functions:
- `asset('blocks.css')` — URL to a theme asset.
- `media(uuid)` — resolve an asset UUID to a public URL (`null` if not anonymously servable).
- `media_image(uuid, widths)` — `src` and `srcset` for an image (`null` if it is not a servable
  image); `claim_priority_image()` is `true` for the first image on the page that asks, and
  always `false` inside a region.
- `blocks(list)` — render a list of **child blocks** (nesting; e.g. hero links, carousel slides).
  Blocks nest up to five levels deep (container → container → card → container → heading); a
  deeper list renders nothing and the validator refuses it.
- `slot_attrs('field')` — **emit this on the element that wraps a `blocks()` call.** On the
  canvas it renders `data-thallo-slot="field"`, so the builder knows the real element a slot
  occupies — where a dragged block may land, which layout the slot has (a flex row splits
  left/right, a column splits top/bottom, a grid cell takes the end), and where an empty slot sits.
  Outside the canvas it renders nothing. The slot name must be a constant string and must be one
  of the block type's `blocks` fields. On the canvas a dashed placeholder (painted by the preview
  stylesheet) with a + that opens the editor's Blocks tab at the end of that slot and the hint
  "Drag a block here" ends every page-level slot, and fills a block's slot while it is empty
  (also marked `data-thallo-slot-empty`); render the wrapper even when the list is empty — `is_canvas()` says whether you are on the
  canvas, so a wrapper the published page omits can still exist there.
- `is_canvas()` — true while rendering for the editor's canvas.
- `is_preview()` — the same flag under its older name.
- `icon(name)` — render an icon.
- `menu('main')` — items for a named menu.
- `region_blocks(name)` / `region_settings(name)` / `region_style_classes(name, target?)` —
  region HTML / settings / the region's Style tab classes (§3).
- `site_logo(variant?)`, `site_favicon()`, `custom_css()` — site identity.
- `path(uuid)` — an entry's public path; `facets(type, field, limit?)` — term counts for a
  filterable reference field; `video_embed(url)` — `provider` and `id` for a YouTube or Vimeo URL.
- `form_render(block)` — the render payload for a `form` block, or `null`.
- `entries(type, {limit, order, category})` — the newest few published entries of a type (at
  most twelve): what a blog block lists.
- `entry_tree(type, {group: 'section', order: 'order'})` — **every** published entry of a type
  as navigation: `groups` (each `key`, `label`, `items`) in the order of the group field's enum
  options, sorted inside a group by the order field and then by title; and `items`, the same
  pages flat in reading order, which is what previous and next walk. An item is `uuid`, `slug`,
  `href`, `title`, `summary`, `group` — never the entry's body. Up to 500. A docs sidebar
  (`entry/docs.twig`).
- `search_enabled()` — whether the site's search is on. Offer a search box only inside it
  (`_docs_search.twig`), so it is never one that cannot answer.
- `markdown(text)` — render Markdown kept in a plain text field. GitHub-flavoured; every
  heading gets a stable id and a `.heading-anchor` link; raw HTML is stripped and unsafe link
  schemes refused, so the output is safe to emit as it is. A code fence is rendered by your
  theme's own `blocks/code.twig`. `markdown_toc(text)` is the same render's `h2`/`h3` outline,
  a list of `id`, `text`, `level`.
- `layers_stylesheet_url()`, `theme_stylesheet_url()`, `settings_stylesheet_url()` —
  the three stylesheets a layout links (§2, §12).
- `runtime_script()` — the package theme runtime's URL (§2). `block_script(name)` — a deferred
  script tag for one block's asset, once per render: `animated-text`, `code`, `docs-search`,
  `gallery` or `motion`.
- `font_faces_style(family, roman, italic?)` — a preload link and `@font-face` rules for a
  webfont in the theme's `assets/` (§9.6).
- `color_mode_enabled()`, `color_mode_script()` — colour mode (§8).
- `theme_colors_style()` — the site's accent, neutral and design-settings override (§9);
  `theme_style_scope(accent, neutral)` — a `style` block's scoped re-skin (§10).
- `seo_head()` — the page's SEO tags; `json_script(value)` — JSON that is safe inside a
  `<script>` element.
- `shop_product_url(slug)`, `shop_category_url(slug)`, `shop_index_url()`,
  `shop_wishlist_scope()`, `shop_wishlist_url()`, `plan_checkout_url(key)` — commerce and
  subscription links, `null` when those packages are absent.
- `style_classes(target)`, `style_attrs(target)`, `token_class(property, value)` —
  a block template's style targets (§12.3). **Every block template emits the first
  two on each target its block type declares.**

Filters:
- `|editable_text('field')` — **use this on editable text.** It emits the
  in-place editing hooks (`data-thallo-edit-field`) so the field is editable on
  the canvas. Plain `{{ data.title }}` renders but isn't editable.
- `|safe_html` — sanitize + emit author-authored rich HTML (rich-text / `body`).
- `|safe_url` — sanitize a URL attribute.
- `|numeric_clamp(min, max)` — a number held between two bounds, `null` when not numeric.
- `|br_tokens` — turn the literal tokens `<br>`, `<br/>` and `<br />` into line breaks; the
  rest stays escaped.

### 4.3 Editing hooks (why you're free to restyle)

- The **render engine** wraps each block with `data-thallo-block` on the canvas —
  the theme does **not** add that. So the editor finds blocks by data-attribute,
  **not** by the `thallo-block-*` CSS classes.
- In-place text editing comes from the `|editable_text('field')` filter, not from
  a class.
- Slots come from `slot_attrs('field')` on the element that holds `blocks()` — one per
  `blocks` field of the type. The **template lint** refuses a template whose type declares a
  `blocks` field it never names with `slot_attrs`, unless the type renders its children's data
  inline (`renders_children_inline` in its flags, as `accordion`, `stepper`, `tabs`, `carousel`,
  `gallery` and `pricing_table` do) and so has no slot.

**Consequence:** presentational classes are yours to change freely. The only
classes/attributes you must keep stable are the ones the package runtime selects
(see the carousel example).

### 4.4 The block set

`accordion` · `accordion_item` · `animated_text` · `audio` · `blog_posts` ·
`button` · `card` · `carousel` · `code` · `collapsible` · `color_mode` ·
`container` · `cta` · `feature` · `file` · `footer` · `form` ·
`gallery` · `heading` · `hero` · `html` · `icon` · `image` · `links` ·
`logo` · `logos` · `navigation` · `pricing_feature` · `pricing_plan` ·
`pricing_plans` · `pricing_table` · `pricing_tier` · `rich_text` ·
`separator` · `shortcode` · `social_link` · `social_links` · `spacer` ·
`stepper` · `stepper_item` · `style` · `tab` · `tabs` · `video`

`accordion_item` and `stepper_item` are **item carriers**: their parent
(`accordion` / `stepper`) renders them inline from `item.data.*`, and they also
have a standalone template for when one is dropped on its own. The single source
of truth for this set is `core/src/Content/Blocks/StarterBlockTypes.php` (schema) — the
template set mirrors it one-to-one. `code` ships `block-code.js` for its Copy button
(the floor is the plain `<pre><code>`). The button copies the code element's **text**, which is
why a `bash` snippet's prompt is never text: `blocks/code.twig` renders each line as a
`thallo-block-code__line`, takes a leading `$ ` off and marks the line `--prompt` for the theme
to draw it with generated content, marks a `#` line `--comment`, and puts the newline inside
every line but the last so nothing pasted into a terminal runs by itself. Other languages stay one
text node under `language-*`, for a highlighter; `shortcode` renders `shortcodes/{name}.twig`, and the
default theme ships `copyright` and `thallo-version` (the running install's version as
`site.version`, styled as a pill; recolour it from custom CSS through `--version-fg`,
`--version-bg` and `--version-dot` on `.thallo-shortcode-version`).

A shortcode block is two elements, and they are two style targets. `root` is the
layout-neutral wrapper held to the page measure: spacing, visibility and the item settings
land there. `content` is whatever the shortcode renders, and takes what gives it a look —
background, text and border colour, border, radius and shadow. Only the shortcode's own
template knows which element that is, so `blocks/shortcode.twig` calls the helper and hands
the result down: the include receives `style.classes` beside `params` and `site`, and puts it
**inside its element's class attribute**:

```twig
<span class="my-shortcode{{ style.classes|default('') }}">…</span>
```

Classes only: the author's anchor, classes and attributes belong to the root, and markup cannot
be handed to an include without `raw`, which the template policy refuses. The target is
optional — a shortcode that ignores `style.classes` renders as it always did and is simply not
styleable. Give such an element its own defaults in the theme (`@layer theme`): the settings
are in the layer above and win. A default `border: 0 solid var(--line)` lets a border *width*
set in the Style tab show by itself.

`thallo-version` draws its dot in the text's colour (`var(--version-dot, currentColor)`), so
recolouring the text brings the dot along. Two `params` adjust it: `"dot": false` hides it, and
`"dot_color"` takes one of the theme's colour names — `accent`, `text`, `muted`,
`accent-contrast`, `background` — becoming `thallo-shortcode-version--dot-{name}`. `params` is
free JSON, so any other value is ignored rather than written into the class attribute.

Two blocks carry presentation choices an operator picks in the editor, each a closed
enum that becomes a BEM modifier (unknown stored values degrade to the default):

- `hero.background` — `gradient` (the default), `none`, `muted`, `inverted` →
  `thallo-block-hero--bg-{value}`; `hero.aside` — any blocks, rendered in the media
  slot instead of the image (`thallo-block-hero__media--blocks`), so a code snippet or
  a card sits beside the copy in the horizontal orientation.
- Button corners are a radius setting on the button's `control` target (`t-radius-*`,
  §12.3); unset, the button reads `--radius-btn`, which the site's design settings
  write (§9.6). Heading alignment and colour, image width and placement, and the
  carousel's `speed` (`slow`, `normal`, `fast` → `thallo-block-carousel--speed-{value}`)
  follow the same rule: choices and tokens, never freeform values.

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
{% set sizeMod = {
  'sm':'thallo-block-card--sm', 'md':'thallo-block-card--md', 'lg':'thallo-block-card--lg'
}[data.size|default('md')] ?? 'thallo-block-card--md' %}
```

**Colour, spacing, corners, border, shadow → settings, never fields or inline
style.** A block declares the capabilities its root target accepts and emits them
through `style_classes()` (§12.3); the compiled settings artifact carries the
utilities. A template never writes a `style=` attribute — the lint refuses it — and
the only inline `<style>` elements are the enumerated variable-only emitters (the
style block's skin scope).

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

One token is deliberately NOT re-skinnable: `--hero-fallback-bg` (the media-less
hero-slider slide base) is theme-invariant dark in both color modes because the
hero-carousel overlay ink is white in both — a custom theme that copies the hero
slider rules must carry this token (with no dark-mode override) or the no-image
slide loses its contrast guarantee.

---

## 6. Examples

### 6.1 `hero` — the reference block (BEM markup, hand-authored CSS)

```twig
{# Fields: headline, title, description, links (nested button blocks),
   image (asset → media()), aside (nested blocks; takes the media slot),
   orientation (vertical|horizontal), reverse, background (gradient|none|muted|inverted). #}
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
      {% if data.links|default([]) is not empty or is_canvas() %}<div class="thallo-block-hero__links"{{ slot_attrs('links') }}>{{ blocks(data.links|default([])) }}</div>{% endif %}
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
- `slot_attrs('links')` names the element the `links` slot occupies; on the canvas the wrapper
  renders even with no links so the slot has a placeholder to drop into.
- `media(data.image)` resolves the asset; `blocks(data.links)` renders the nested
  link blocks.
- The `thallo-block-hero*` classes are the styling surface AND a stable hook for
  overrides — but they are **not** how the editor finds blocks (that's the
  `data-thallo-block` wrapper the engine adds).

### 6.2 `carousel` — a JS-driven block (keep the hooks)

The package runtime (`runtime_script()`, §2) enhances carousels by selecting
`.thallo-block-carousel`, `.thallo-block-carousel__viewport`, and
`.thallo-block-carousel__track`, and reads `data-arrows` / `data-dots` /
`data-autoplay` / `data-transition` / `data-speed`. **These are behavior hooks — keep them**; all looks come from the
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
- **Large / interactive blocks get their own file** (`navigation.css`,
  `stepper.css`). Wire a new one by adding it to `stylesheets` in `theme.json` —
  it rides inside the theme artifact; the layout never links files one by one.
- **No `!important` on a managed property** (§12.1) in a rule that targets a
  `.thallo-block*` selector, and no `@import`: the artifact build refuses both.
- **Interactive disclosure blocks** (`accordion`, `collapsible`) are native
  `<details>` — CSS-only, no JS. The default theme ships no script: behaviour is the
  package's, in the runtime (`carousel`, `tabs`, `navigation`, `form`) and the per-block
  assets `block_script()` loads.
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

Drop the **Color mode** block (`color_mode`) into a region (it's in the Content
category of the palette) to give visitors a light / system / dark switch. It renders a
three-option segmented control; each option carries `data-color-mode-set`
(`light` | `system` | `dark`). The package runtime (`runtime_script()`, §2) wires the clicks,
persists the choice, updates `data-theme`, reflects the active option
(`aria-checked`), and dispatches a `thallo:color-mode-change` event on
`<html>`. `window.thalloColorMode` (`get()` / `set()` / `resolved()` / `reflect()`) is
available for custom controls.

### 8.4 Turning it off

Set `THALLO_COLOR_MODE_ENABLED=false` (config `theme.color_mode.enabled`). With
color mode **off**: no resolver script and no `data-color-mode-enabled` marker
are emitted, the runtime's colour-mode code stays inert (even if `localStorage` still
holds a stale `dark`), and the `color_mode` block renders nothing. The site
falls back to the light tokens.

### 8.5 Content-Security-Policy

The resolver is one of two inline scripts (the other is the motion flag, §12.6, on a page
with an entrance animation), and both are byte-stable. If you run a
strict CSP, allow it by **hash** (no `unsafe-inline`, no per-request nonce that
would break page caching). Add to `script-src`:

```
script-src 'sha256-LPPpGD9ammrw92nJUwoMRPu1xnHk26P8c3tFKYUe8OE='
```

The digest is published as `Thallo\Render\ColorMode::RESOLVER_SHA256`
(`base64(sha256(RESOLVER_JS))`); a test fails the build if the script bytes ever
drift from it, so this value stays correct. The `.env.example` a new site starts from sets
`CSP_HEADER` to a policy that allows `script-src 'self' 'unsafe-inline'` and sends it
report-only (`CSP_REPORT_ONLY=true`), so the resolver runs under it. The hash matters once you
drop `'unsafe-inline'` from `script-src`.

## 9. Theme colors (accent + neutral)

An operator can re-skin the theme by choosing a brand **accent** and a
**neutral** tone from **Site › Appearance → Theme colors**. It re-maps the
design **tokens only** — it never swaps a template — and applies in both light
and dark mode.

### 9.1 What's configurable

- **Accent** — one Tailwind hue family: `red, orange, amber, yellow, lime,
  green, emerald, teal, cyan, sky, blue, indigo, violet, purple, fuchsia, pink,
  rose` — **or the site's own brand colour, as a hex** (`#0a7c66`; `#abc` is written out).
- **Neutral** — one Tailwind neutral family: `slate, gray, zinc, neutral, stone`. Always a
  family: a whole grey scale cannot be derived from one colour.
- **Defaults `blue` / `slate`** reproduce the shipped look exactly.

A save `422`s on anything else. Stored in `GeneralSettings` as `theme_accent` /
`theme_neutral`.

**A brand colour is used exactly as given on a light page** — the brand is the brand — and
Thallo derives what the owner cannot be asked to work out:

- `--accent-ink`, the label on the accent, is whichever of white and black reads on it. (For a
  family it is always white: each family's light stop is dark enough for white to clear AA.) Between
  the two, one always clears WCAG AA, so a button's label is always readable. **Read
  `--accent-ink` for anything you put on `--accent`**; never assume white. And use it ONLY
  there: text on an ink fill reads `--bg` (the pair that inverts in both colour modes), and
  text over pictures and dark scrims reads `--on-media`, which no setting changes. The default
  theme is held to this by a test.
- On the dark ground the colour is lifted toward white until it can be seen there (the
  families do the same with a lighter stop), keeping its hue.

The Appearance page says how the colour will read before it is saved, including the one thing
Thallo does not change: a light brand colour is hard to read as link text on a white page.
Only the site-wide accent may be a hex; the scoped Style block (§10) stays families, because
its class is built from the family's name.

### 9.2 How it re-skins (tokens only)

Each family maps to concrete token values (light + dark) via a curated table
(`Thallo\Render\Theme\ThemeColors`). A `theme_colors_style()` function emits a
`:root { … }` + `html[data-theme="dark"] { … }` override in `<head>`, **after
the theme's stylesheets and before `custom.css`** so custom CSS stays the final
escape hatch. Because every block paints from `var(--…)`, the whole theme flips
with no per-block rules — and the dark accent now comes from the chosen family
(replacing the old hard-coded blue).

**The default emits nothing.** `blue`/`slate` lives canonically in `site.css`, so
a default site's HTML stays override-free; only a non-default pair emits a style.

### 9.3 Preview before apply

The Appearance page's **Preview** card frames the site's homepage wearing the *pending*
(unsaved) look: theme, colours and design settings as they stand in the form. Each change
mints a preview session and the frame loads it; **Open** shows the same session in a new tab.
The choices are **signed into the preview token** and applied for that session only — they are
never written to settings until you **Save**. Exiting/expiring the preview reverts to
the saved look with no residue. The preview opens through the homepage entry, so a site with
none set gets no preview. A new logo or site icon is not in the token and shows once saved.

### 9.4 Caching

The render page cache (and the fixed 404/410 bodies) key on every resolved appearance
choice — `render:{theme}:{accent}-{neutral}-{radius}-{font}-{background}:{path}` — and a save dispatches
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

This is acceptable because the style is generated from **closed enums**, not
free CSS (a far narrower trust surface than `custom.css`). The policy in the shipped
`.env.example` already allows `style-src 'self' 'unsafe-inline'`. If strict-CSP perfection is
later required, the same storage + token model can serve the CSS from a linked `/theme-colors.css` route instead —
a delivery-only change.

### 9.6 Design settings (radius, typefaces, page ground)

Next to the colours, **Site › Appearance → Design** carries three more closed enums,
stored as `theme_radius`, `theme_font`, `theme_background` (and, for the site's own fonts,
`theme_font_body` / `theme_font_display`) and emitted by the same
`theme_colors_style()` block (`Thallo\Render\Theme\ThemeDesign`), after the colours:

- **Corners** — `round` (default: `--radius: 12px`, pill buttons), `soft`
  (`--radius: 12px`, `--radius-lg: 20px`, `--radius-btn: 8px`), `sharp` (`--radius: 4px`,
  `--radius-lg: 8px`, `--radius-btn: 4px`). Buttons read `--radius-btn` unless a radius setting is set.
- **Typefaces** — `sans` (default: Figtree throughout), `editorial` (a system serif
  stack for `--font-display`, so headings), `serif`, `humanist`, `geometric`, `mono` and
  `system` (both `--font-display` and `--font-body`), and `slab` (headings only). All system
  stacks: none downloads a font of its own and the site's CSP stays `'self'`. `editorial` and
  `slab` set only the headings, so the text stays in the theme's face and that face is still
  downloaded (see below).
  **`custom` is the site's own fonts**: a `.woff2` for the text, one for the headings, or
  both, uploaded on the Appearance page into the media library. Each is declared with
  `@font-face` from the URL the library serves it at (`font-weight: 100 900`, so a variable
  font covers every weight from one file) and put first in `--font-body` / `--font-display`
  with a system stack behind it. With only a text font, headings follow it. A font the library
  no longer has is simply not used.
- **Your theme's own font is not downloaded when nothing is set in it.** `font_faces_style()`
  emits its preload and `@font-face` only while the site's text is still the theme's face —
  for `sans`, `editorial`, `slab` and `custom` without a text font; not for `serif`,
  `humanist`, `geometric`, `mono`, `system`, or `custom` with a text font.
- **Page ground** — `plain` (default: white page, tinted panels) or `tinted`, which
  swaps the neutral family's `--bg` and `--surface` in light mode (a tinted page with
  white panels); dark mode is unchanged.

`site.css` declares `--font-body` and `--font-display` (display follows body by default)
and `body`/headings read them, so a theme inheriting the default layout gets the settings
for free. Defaults emit nothing. Every choice is in the cache fingerprint (§9.4 —
`render:{theme}:{accent}-{neutral}-{radius}-{font}-{background}:{path}`) and a change
dispatches `ThemeAppearanceChanged`.

## 10. Style block (scoped accent/neutral)

The **Style** block (`slug: style`, category Layout) re-skins a group of blocks
without swapping templates — the local sibling of the global theme color config (§9).

### 10.1 What it configures
- **Accent** and **Neutral** — the same closed Tailwind families as §9. Each is
  optional; the first option, **Inherit**, leaves that dimension unchanged.
- **Content** — the child blocks the skin applies to.

Padding, margin and shadow are settings on the block's root target (§12.3); a
custom-CSS hook is the Advanced tab's CSS classes, emitted verbatim on the wrapper.

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

### 10.4 Preview & caching (inherited, no new machinery)
Style values are ordinary published block content, so they preview through the normal
content preview and their rendered HTML is invalidated by the existing content/publish
cache purge (the render entry is tagged with the page's entry surrogate). There is no
separate preview token, appearance fingerprint, or purge listener for this block.

## 11. Shadows (elevation scale)

The theme ships a Tailwind-derived elevation scale as design tokens in `site.css`,
light + dark aware; the vocabulary's `shadow.*` tokens (§12.1) map onto it, so a
block's shadow is a setting.

### 11.1 The scale
`--shadow-none`, `--shadow-2xs`, `--shadow-xs`, `--shadow-sm`, `--shadow-md`,
`--shadow-lg`, `--shadow-xl`, `--shadow-2xl`. `--shadow` aliases `--shadow-md` (the
default), so every component that used the old flat shadow now renders md; floating
overlays (nav dropdown) use `--shadow-lg`. A block takes a depth through its `shadow`
setting (`t-shadow-{token}`); theme CSS reads the variables directly.

### 11.2 Overridable color + opacity
Each token composes its color from `--shadow-color` and its opacity from
`calc(<base>% * --shadow-strength)` via `color-mix()`. Defaults: light slate-900 /
strength 1; dark black / strength 2.5 (the scale recomputes automatically in dark —
no separate dark shadow values). Override either variable on an element for a colored
or stronger/softer shadow.

---

## 12. Style contracts (the visual builder)

Block settings — padding, width, alignment, typography, colours, radius, border,
shadow, visibility — are typed values authored in the editor, never CSS. Delivery
is layered so a theme keeps every default and a setting always wins:

```
/_thallo/layers.css                     @layer theme, settings;
/theme-assets/theme-{hash}.css          @layer theme    { every manifest + contributed sheet }
/theme-assets/settings-{hash}.css       @layer settings { :root { --t-* } utilities, resets }
```

### 12.1 The vocabulary

The platform owns the names; the theme maps each to a CSS value (`theme.json`
`vocabulary`, §1). Names are ordinal scales, never pixel promises:

| domain | names |
|---|---|
| `spacing` | `none xs sm md lg xl 2xl 3xl` |
| `width` | `narrow content container full` |
| `radius` | `none sm md lg full` |
| `color` | `background surface surface-2 text muted line accent accent-contrast transparent white` — `white` is literal `#ffffff` in every scheme (text on an accent or inverted band); a theme that omits it gets the literal |
| `shadow` | `none xs sm md lg xl` |
| `typography.size` | `xs sm md lg xl 2xl 3xl` |

The compiled artifact turns the mapping into `--t-spacing-lg` and friends and one
utility per managed property, value and breakpoint (`t-pt-lg`, `md:t-pt-lg`,
`lg:t-pt-reset`); `revert-layer` resets hand a breakpoint back to the theme. Map
to your own `var(--space-4)`-style variables so the design settings (radius,
typefaces, page ground) keep re-mapping live. A missing name fails validation at
load, on theme switch, in `thallo:doctor` and in `thallo:provision`, which compiles
the artifact before anything links it and refuses to complete when it cannot.

### 12.2 The theme artifact

Everything in `stylesheets` (plus package sheets such as the storefront's) is
concatenated inside `@layer theme` and served by content hash, immutable, so a
CSS edit re-keys every cached page. The build refuses `@import`, `@charset` and
`@namespace`, and `!important` on a managed property in any rule targeting a
`.thallo-block*` selector — a setting must always be able to win.

### 12.3 Block style targets

Each block type declares `style_capabilities` (property paths or groups) and
`style_targets`: named parts of its template with a layout kind (`text`, `row`,
`stack`, `box`) and the capability → target map. The starter library declares
them for every shipped block (`StarterBlockTypes`; contributed packs through
`StarterBlockTypeDefinition`). A template styles a target with two helpers,
placed on the element the target names:

```twig
<div class="thallo-block thallo-block-button{{ style_classes('root') }}"{{ style_attrs('root') }}>
  <a class="{{ linkClass }}{{ style_classes('control') }}"{{ style_attrs('control') }} href="…">
```

`style_classes(target)` returns the utility classes the block's settings resolve to
for that target (with a leading space); `style_attrs(target)` returns only the
attributes the target owns — `id` from the anchor, `data-*` attributes, the
accessibility label — escaped. Block semantics that stay in `data` as `token` or
`choice` fields (animated text's per-part colours) go through
`token_class(property, value)`, which emits the same utility the compiler does.

The Background setting (`colors.surface`) compiles to the `background` shorthand, so it
owns the whole background: a theme rule that paints a block with a gradient or an image
background (the hero's `--bg-gradient` band) yields to a managed colour, `transparent`
clears it, and a reset gives the theme's background back.

The template lint (the same policy the admin editor enforces) holds a block
template to its declaration: every declared target is styled, no undeclared
target is used, and target names are constant strings; every `blocks` field is a
slot named once by `slot_attrs` (§4.3). A DB override of a shipped block template
is held to the same rule. No template writes a `style=` attribute
or a `<style>` element — the lint refuses both at save and before render; the only
inline style emitters are `theme_colors_style()`, `theme_style_scope()` and
`font_faces_style()` (variables and `@font-face`, no selectors).

The feature block has a third target, `marker`: its icon chip or number badge. `marker.radius`
and `marker.shadow` (Style tab → Marker) land there, while the block's `radius` and `shadow`
stay the card's, on the root — two elements, two slots. A theme that overrides `feature.twig`
adds `{{ style_classes('marker') }}` inside the marker's class attribute and
`{{ style_attrs('marker') }}` on its tag; the target is optional, since a feature with no marker
renders no element.

The tabs block has two targets for its strip, beside `panels`: `bar`, the list, takes
`tabs.bar_radius`; `tab`, the label, takes `tabs.tab_radius` (Style tab → Tabs). The block's own
`radius` stays the panels area's. A theme that overrides `tabs.twig` styles `bar` on the list and
`tab` on **every** label: which tab is active is decided in CSS, by the checked radio, so the pill
an author rounds is whichever label is showing it. `tab` is optional — a tabs block with no tabs
has no label. Being settings, both outrank a variant's own corners: an author who rounds the bar
of the `underline` or `boxed` variant gets a rounded bar.

Three properties modify what others declare. **Border sides** (`border.sides`) keeps one side of
the border the width and style settings draw; it is in the `border` group, so any block that
declares a border has it. **Background opacity** (`colors.surface_opacity`) and **Backdrop blur**
(`backdrop.blur`) are the `backdrop` group, which a block type opts into — the container does, and
so do the header and footer regions. The opacity mixes the colour chosen in the Style tab, or the
one the theme names in `--t-surface-default` on that element (see Regions, above); a blur shows
only through a background that is not opaque.

The hero's gradient reads two variables, each with the theme's own value as its fallback:
`--hero-gradient` (else `--accent`) and `--hero-gradient-strength` (else `9%`). The hero's
*Gradient color* and *Gradient strength* fields set them through classes
(`thallo-block-hero--gradient-{family}`, `…--gradient-strength-{medium|strong}`); the families are
the site accent's palette, light and dark. A theme with its own hero styles reads the same two
variables to honour the fields, or ignores them and keeps its own gradient.

A block's **default** corners read the same scale the Style tab's tokens name: `--radius-sm`
(6px), `--radius-md` (`var(--radius)`), `--radius-lg`. A theme defines all of them. `var(--x)`
with no fallback, where `--x` is never defined, makes the whole declaration invalid and the
property silently takes its initial value — which is how a badge ships square while its
stylesheet says rounded. A test holds the default theme to it: every custom property it reads
without a fallback is one it defines.

#### A block type made in the admin

A block type created under **Settings › Block types** gets style settings too, without code. Its
editor has a **Style settings** card: tick the setting groups the block should offer in the
designer — spacing, width, placement, typography, colours, backdrop, corners, border, shadow,
visibility, minimum height, overflow, sizing in a parent layout, and motion. Such a block has **one** style
target, `root`, a box: its outermost element. Every chosen group lands there, and so do the
Advanced tab's anchor, CSS classes and attributes. The template emits them:

```twig
{# blocks/promo_banner.twig #}
<div class="promo-banner{{ style_classes('root') }}"{{ style_attrs('root') }}>
  <h2>{{ data.title }}</h2>
</div>
```

The order matters once: choosing groups for a block whose template does **not** emit them is
refused, with the line to add, because that template would fail the lint below and stop
rendering. Add the two helpers first, then tick the groups. For a new block type there is no
template yet, so tick the groups first; the Theme editor then refuses a template that leaves the
helpers out.

Not offered to a block made in the admin: text alignment (it needs a `text` target) and the
parent-layout groups (a `stack`: what arranges a container's children). A block that needs
several targets, or those kinds, is declared in code. The style settings of a block type Thallo or
a pack declares are shown read-only: they are set in code and re-synced on every upgrade.

### 12.3a The container's layout (what a theme must keep)

A container is two elements: `root`, the band, and `__inner`, the content area. Its
layout is settings, not data — there are no `--layout-flex`, `--gap-*` or `--contained`
modifier classes to style. It arranges its children in one of two modes, Flex or Grid;
there is no block mode. A `block` value stored by an older version compiles to nothing, so
the theme's flex column renders; the Layout tab shows it in place of the mode control, says
where it is set (the block or a style class, and at which breakpoint), and refuses to save it
until it is replaced with Flex or removed. These theme defaults carry the contract, and a theme that
restyles the container keeps all of them:

```css
.thallo-block-container {
  --thallo-root-layout: block;          /* min height sets this, never `display` */
  display: var(--thallo-root-layout);
  flex-direction: column;
}
.thallo-block-container__inner {
  --thallo-default-gutter: 0px;          /* the content-width utility sets it */
  padding-inline: var(--thallo-default-gutter);
  flex: 1 1 auto;                        /* fills a tall band */
  width: 100%;                           /* auto inline margins cancel the stretch */
  display: flex;                         /* the default mode: a flex column … */
  flex-direction: column;
  gap: var(--space-5);                   /* … spaced by the margin the gap replaces */
}
.thallo-block-container__inner > .thallo-block,
.thallo-block-container__inner > .thallo-preview-block > .thallo-block { margin-block: 0; }
```

Routing the band's display through a variable is what lets a minimum height size it
while managed visibility keeps sole authority over `display`: a hidden band stays
hidden however tall it is told to be. The gutter is initialised on the element itself
so a boxed ancestor's gutter never reaches a full-width container nested inside it.

Two more defaults govern what happens to the children. Blocks that clamp themselves to
the page measure have that clamp released inside a container, so a block in a cell does
not carry a second gutter; the release names exactly the blocks that have one
(`tests/fixtures/layout/containment-inventory.json` records them, and a test holds the
two in step). And the gaps are the one source of spacing between a container's
children: no child carries a default vertical margin, in either mode, at any breakpoint
or after a reset, and the container's own padding governs its boundary. The default
for both gaps is `--space-5` — the margin it replaces — so a container nobody has
touched stacks its children at the distances block flow gave them, and a row or a grid
starts spaced rather than touching; `none` is a choice an author makes. No rule names a
mode, because there is no mode in which the margin comes back. Authored values always
win over all of this: a margin, a padding, a gap or a width an author set is a setting
in the layer above.

A theme that ships its own `blocks.css` therefore has three things to provide, and the
contract has nothing to fall back on if it does not: the content area's `display: flex`
with `flex-direction: column`, its `gap`, and the release of the children's vertical
margin. Leave out the first and an untouched container falls back to block flow with
neither margins nor gap, so its children touch; leave out the gap and the same happens
in every mode. Nothing else is asked of a theme here — the Design view's grid outline
and its Fill empty cells button are the editor's own, drawn from the tracks and gaps the
browser resolved, and never reach a public page.

An authored **width** means "fill the available space, up to this maximum": the
compiled utility sets `width: 100%` beside its `max-width`. That relies on
`box-sizing: border-box`, which the default theme sets on every element — under
content-box a padded block with an authored width would overflow its container, so a
theme that changes the sizing model must account for it. In a flex row the width is
the item's starting size (`flex-basis: auto` reads it), so it sizes items and can wrap
them; an explicit basis takes its place.

### 12.4 Style classes (the class layer)

A style class is a site-owned, theme-independent record: a name and a `style` in the
same schema a block's own settings use, with sparse breakpoints and resets. A block
lists the classes it composes in `settings.classes`, in order; the cascade resolves
each class as a layer below the block's own settings, later classes over earlier
ones (spec §1.6), and `style_classes()` emits the result exactly as it does for
instance values. A class declares no capabilities or targets: applied to a block,
each declaration lands only where the block has the capability and is dormant
elsewhere. Themes never see classes as such — only the utilities the cascade
resolves to — so a theme needs nothing new for them.

A class may carry layout as well as styling — a mode, a direction, tracks, gaps, a width,
the item settings — and the rule is the same one: the cascade resolves **per property**.
So a class's settings are not tied to the mode that class sets. A class that sets Grid
and also declares a direction contributes that direction to any block whose *effective*
layout is Flex, because the block itself or a later class set it so; the item settings
likewise follow the mode of whatever parent the block ends up in. This is why the class
editor labels each group by where it applies rather than hiding the ones its own mode
does not use, and why a theme should not assume that a utility for direction only ever
arrives alongside the flex display utility. An explicit reset in a class is a
declaration too: it returns the property to the theme's value from that breakpoint up,
over whatever a lower layer supplied.

Every class write increments the site's style generation, which names the exact set
of class records a render resolved through. The page-cache key carries it
(`…-g<generation>`), and the canvas page carries it on `<main>` as
`data-thallo-style-generation` next to the revision pair, so an editor can tell when
the classes it resolved with have changed.

### 12.5 Browser floor and proofs

The public site requires cascade layers, `revert-layer` and `color-mix()`:
Chrome 111, Firefox 113, Safari 16.2. `tools/style-proofs` proves the computed
result of the cascade in Chromium, Firefox and WebKit against the real artifacts
(see its README).


### 12.6 Motion (entrances, stagger, Ken Burns)

Motion is a group of style settings like any other: the editor picks a preset in the Style
tab's **Motion** group, the emitter writes a utility class on the block, and the settings
artifact carries the rules. A theme writes no CSS and no JavaScript for it.

| Setting | Values | Lands on |
|---|---|---|
| Entrance | none, fade, fade-up, fade-down, slide-left, slide-right, zoom-in | the block's root |
| Duration | fast (300ms), normal (600ms), slow (1s) | the block's root |
| Delay | none, short (150ms), medium (300ms), long (600ms) | the block's root |
| Repeat | once, every time it scrolls into view | the block's root |
| Stagger children | none, short (80ms), medium (150ms), long (250ms) a child | the container's `inner` |
| Ken Burns | none, zoom-in, zoom-out, pan-left, pan-right | a picture frame (below) |

Every starter block takes an entrance except the ones that are a part of another block or
have nothing to show (a tab, an accordion item, a spacer, animated text). A block type made
in the admin takes one when its **Motion** group is switched on (§12.3).

**How an entrance runs.** A block that enters is hidden until it scrolls into view, so the
page must know *before that block is parsed* that the script which reveals it is coming;
otherwise the block would paint and then vanish. The renderer notes, while it renders, that a
block enters, and the finished page gets one small inline flag in its `<head>`, with a deferred
`/_thallo/runtime/block-motion.js` beside it. It is never written beside the block: a script
among blocks is a sibling, and your `:first-child` or `+` rules would see it. A page with no
entrance pays nothing. The
flag sets `data-thallo-motion` on `<html>`; every entrance rule is scoped to that attribute
**and** to `prefers-reduced-motion: no-preference`, so a visitor who asks for reduced
motion, a browser without `IntersectionObserver`, and a page whose script never arrives
(the flag withdraws itself after three seconds) all get the block simply shown.

**Stagger** delays each direct child of the container by its place, up to the twelfth;
the children still need an entrance of their own. It does not reach grandchildren.

**Ken Burns** is CSS only. The setting lands on a *frame*: the frame clips
(`overflow: clip`) and the picture that is its **direct child** (`img`, `picture` or
`video`) drifts slowly back and forth. Two starter blocks have one: the Container (its
background image or video, under `root`) and the Hero (its picture, under `media`), which
makes a hero slide in a carousel drift as well. The Image block is deliberately not a
frame: its figure also holds the gutters and the caption, which a drifting picture would
cover. Make the picture a container's background instead.

**What a custom theme must keep.** For entrances: nothing beyond emitting
`style_classes('root')`, which the template lint already requires. The flag goes before your
layout's `</head>`; a layout that leaves its head implied gets it before `<body>`, or straight
after the doctype. A package that renders pages of its own calls
`RenderContextExtension::finish($html)` on the result, as the shop and account pages do; a
page that is not finished has no flag and its blocks are simply shown. For Ken Burns: keep the
picture a direct child of the element that emits the frame's target, and do not set a
`transform` of your own on that picture.

**In the editor** motion is held still: the canvas never receives the flag and a Ken
Burns picture does not drift, because a hidden or moving block cannot be edited. The Motion
group's **Play** replays the selected block once on the stage.

**Content-Security-Policy.** The flag is the second byte-stable inline script (after the
colour mode resolver, §8.5). Under a strict CSP add its hash to `script-src`:

```
script-src 'sha256-oITHwt56P1Z4Ld9JC9e+G5nYf5B76v5qE0R8X48Xz9E='
```

It is published as `Thallo\Render\Motion::FLAG_SHA256`, and a test fails the build if the
script bytes drift from it.
