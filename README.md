# glueful/lemma-render

**Rendered delivery** for [Lemma](https://getlemma.dev) — the CMS serves real HTML pages
from published content through filesystem **Twig themes** — packaged as a **removable
capability pack** (V2 rendered-delivery sub-project 2; see `docs/V2_DESIGN.md`). With the
pack absent or `lemma.render` disabled, the install is exactly the headless product:
unmatched public paths return the router's standard JSON 404.

## How a page renders

One lowest-priority **catch-all** (`GET /{path}` in the router's `*` bucket — every
static route and literal-prefix bucket wins first) hands raw paths to the
**`PublicRouteResolver`** contract, which core implements over the existing routing/
addressability layer:

1. **Normalization first:** trailing/duplicate slashes 301 to the canonical path before
   any lookup.
2. **Parse** against the route template — `/{locale}/{type}/{slug}` when the first
   segment is an active locale, `/{type}/{slug}` in the default locale (`/blog/hello`
   is always type `blog`, never locale `blog`).
3. **Visibility:** render is anonymous — non-public-delivery types resolve `not_found`
   even with a live route.
4. **Resolve:** redirects honored as real 30x, broken redirect targets are 410,
   published entries return the **read-only public delivery shape** (`seo` included —
   byte-identical to the headless API) plus the content-type slug for template selection.

Reserved paths (`lemma_render.reserved_prefixes` — segment semantics — and
`reserved_exact`) return the framework's standard JSON 404 so API clients never receive
themed HTML.

## Themes

A theme is `themes/{name}/` with `theme.json`, `templates/`, `assets/`. The pack embeds
the **default reference theme**; an app theme overrides it by name
(`lemma_render.theme`, env `RENDER_THEME`) with **per-template fallback** — omit
`404.twig` and the default theme's serves. Ladder: missing app theme → default; present
but invalid `theme.json` → loud 500; broken pack default → hard 500; template missing in
both → `error.twig` → plain-text 500 (never a loop).

The default theme is a **modern-SaaS reference**: token-driven CSS (colors,
spacing, radius, fluid type) with automatic dark mode, a sticky translucent
header, full-width flow with per-block containers, and NAMESPACED shell
classes (`.site-header`/`.site-nav`/`.site-footer`) so block templates'
own `header`/`nav`/`footer` elements never inherit shell styling. Presentation is a
LAYERED contract: templates consume one composed `presentation` context
(`show_title`, `layout`) resolved as per-page override → `theme.json`
per-type setting → `theme.json` default → built-ins (`show_title: true`,
`layout: 'centered'`). Themes declare defaults in a strict `settings`
block (`{"settings": {"layout": "full", "types": {"pages": {"show_title":
false}}}}` — unknown keys are rejected loudly); editors override per page
from the canvas inspector's **Page** tab. The override lives under the
reserved `_presentation` key in the draft's fields — it versions,
previews, and publishes WITH the page but is **never public content**:
the delivery API strips it from every payload, and schema field names may
never start with `_` (reserved for system keys). `layout` maps to a
`layout--*` class on `<main>`; band blocks render edge-to-edge under
`full` and as contained cards under `centered`.

Hierarchy: `entry/{type-slug}.twig` → `entry.twig`; `index.twig` (homepage);
`404.twig`; `error.twig`; `layout.twig`. Context: `entry` (treat as read-only), `site`
(`name`/`locale`), and functions:

- `menu('main')` — via the `MenuReader` contract; **`[]` when lemma-navigation is absent
  or disabled** (no hard dependency).
- `path(entryUuid)` — live public path, **null unless published** (no dead links, ever).
- `asset('css/site.css')` — `/theme-assets/...`; rejects absolute URLs, `..`, leading
  `/`, backslashes. Only the active theme's `assets/` is served — never templates.

**Escaping:** the reference theme escapes everything — it cannot know that a field named
`body` is sanitized rich text. `|raw` is a deliberate opt-in for theme authors who know
their schema's rich-text fields.

Twig compiles to `storage/cache/twig/{theme}` with `auto_reload` (recompiles on template
change). **The active theme is resolved at boot (v1):** changing `lemma_render.theme`
requires an app restart / extension-cache rebuild.

## Homepage

`GET /` always renders `index.twig`. Set `lemma_render.homepage_entry` (env
`RENDER_HOMEPAGE_ENTRY`) to put that entry in the context; unset renders the standalone
welcome. A set-but-unresolvable value (missing/unpublished/routeless/deleted) is a
**500 config error** — logged always, message in the body only under debug mode.

## Listing & archive pages

Allowlisted types get a rendered listing at `/{type}` and term archives at
`/{type}/{field}/{term}` (the field must be a filterable reference; membership
comes from the same published-reference projection as the delivery archive
endpoint). Pagination is path-based — `/{type}/page/2` — because the render
cache is keyed by path; `/page/1` 301s to the bare path. `page` is a reserved
word as an archive field segment. Templates: `listing/{type}.twig` →
`listing.twig`, `archive/{type}.twig` → `archive.twig`; context ships `items`
(each with a ready `href`; `null` = routeless), `pagination`
(`prev_path`/`next_path` precomputed), and for archives `term` + `field`.
Cached pages carry the broad `lemma:type:{type}` surrogate tag, so ANY publish
of the type purges every listing page immediately.

Term INDEX pages live at `/{type}/terms/{field}` — every term of the field with its
count, each linking to its archive page (500-term cap, no pagination). `terms` is a
reserved word alongside `page`: an archive field literally named `terms` cannot have
rendered archive pages (entries slugged `terms` are unaffected — the reservation only
applies at three segments). A valid field with zero terms renders an empty index;
unknown/non-filterable fields render the themed 404.

| Key (env) | Default |
|---|---|
| `lemma_render.listing_types` (`RENDER_LISTING_TYPES`, comma-separated) | `''` — feature dormant |
| `lemma_render.listing_per_page` (`RENDER_LISTING_PER_PAGE`) | `10` |

## Preview in the theme

`GET /_preview/{token}` renders a draft (or pinned version) through the active theme —
the signed token from `POST /v1/admin/entries/{uuid}/preview/{locale}` is the only
credential (its response's `theme_url` carries this path; `null` = capability off).
Responses are `Cache-Control: no-store` + `X-Robots-Tag: noindex`, never enter the
page cache, and carry a `preview` flag templates can read (the default theme shows a
banner). Preview content has NO `entry.seo` object. All token failures render the
themed 404.

Opening a preview also starts a short-lived **preview session** (a signed cookie that
expires with the token): navigation stays in preview chrome (banner with an Exit
link, `no-store`, `noindex`, never cached), your draft — or, when the canvas has
applied unsaved work, its working copy — appears at its own URL and at `/` when
the entry is the configured homepage, and every other page shows published
content. `GET /_preview/exit` ends the session.
Minting accepts an optional `theme` (validated against installed themes, signed into
the token): the whole session renders through that theme, with assets served from the
token-scoped `/_preview-assets/{token}/…` route. Sessions work with the page cache
disabled; junk cookies never bypass the cache.

## DB-edited templates

Admins with `templates.manage` can override any template the active theme resolves —
or create new hierarchy templates (`entry/interview.twig`) that don't exist on disk —
from the admin Templates screen. Overrides are stored per theme with **append-only
version history** (restore any version; deleting an override falls back to the
filesystem and keeps history). Saves go live immediately: every save is
**statically policy-checked** (allowlisted tags/filters/functions/tests, constant
include/extends targets, no method calls, no `raw`) with line-numbered errors, and
checked again at compile time, so rows written around the API never execute. There is
no runtime sandbox — enforcement is the AST scan plus the arrays-only render context.
`RENDER_DB_TEMPLATES=false` is the ops kill-switch (pure filesystem rendering, admin
routes off). Active-theme saves purge the page cache; per-preview themed sessions see
that theme's overrides, so you can author against an inactive theme and preview it.

## Blocks in templates

`blocks(entry.fields.body)` renders an ordered blocks-field value through the template
hierarchy `blocks/{type}.twig` (theme file or DB-edited template — both work). Each
block template receives `{ block, data, entry, index }`. Missing templates render an
HTML comment in production and a visible placeholder in debug, logged once per type.
Containers nest via `{{ blocks(data.region) }}` up to 3 levels; deeper data renders
nothing. Reference values inside `data` arrive expanded (published item:
`data.post.fields.title`, `path(data.post.entry_uuid)`; `null` when the target is
unpublished or gated; raw uuid only at the expansion-depth cap). Asset values stay
raw blob uuids for `media()`. Pages embedding expanded targets carry the target's
`lemma:entry:{uuid}` cache tag, so they purge when the target republishes.

`php glueful lemma:blocks:seed` (alias `blocks:seed`) seeds ten starter block types
(Layout/Content/Media) with matching default-theme templates — idempotent, skips any
existing slug, never overwrites admin edits. Media blocks render through `media(uuid)`
(public, anonymously retrievable blobs only — set `UPLOADS_ACCESS=upload_only` or
`public`; private/gated blobs render nothing). Link fields render through the
`safe_url` filter (relative, https, http, mailto only). Style enums map to
`lemma-block-{slug}--{value}` modifier classes — restyle by targeting them. The
starter styling ships standalone as `assets/blocks.css`: block TEMPLATES fall back
to the pack default per-template, but assets don't — a custom theme adopts the
starter blocks by copying (or rewriting) that one file.

**Canvas annotation (preview only):** every preview-session render wraps each
`blocks()` instance in a layout-inert `<div class="lemma-preview-block"
data-lemma-block="{id}">` carrier (`display: contents` from the static
`/_preview.css`) and injects the token-free `/_preview-bridge.js` — the visual
canvas maps DOM to block ids through it. Live renders carry neither. **Shape
limit:** block templates that must be literal children of semantic containers
(`ul > li`, `table > tr`) are not compatible with canvas annotation; Lemma blocks
are page/layout fragments, so no starter block is affected.

In a canvas session the bridge also renders a small toolbar on the selected
block (drag to reorder, move up/down, duplicate, delete, add block after). The toolbar posts
intents to the admin canvas; the block tree is mutated there, and the canvas
answers with mirror commands that update the preview DOM optimistically until
the next Save & refresh re-renders the truth. All toolbar styling lives in the
static `/_preview.css` (never inline styles); the toolbar is positioned by DOM
placement inside the selected block's first element, so blocks whose templates
render no element (text-only output) get selection but no toolbar.
While dragging, a compact ghost of the block follows the cursor and the
page auto-scrolls when the pointer nears the viewport edges.
The selected block also answers the keyboard: Alt/Option+Arrow moves it,
Backspace/Delete asks the admin's delete confirm, Cmd/Ctrl+D duplicates,
Enter opens in-place editing when the block has exactly one editable region
of its own (a container's child-block regions don't count — the same rule
the wrapper-level double-click uses), and Escape deselects. Shortcuts stay
inert while editing in-place, while dragging, and while focus sits in the
toolbar or the theme's own form fields.

With the admin's Apply action, the preview session can also render the
editor's *unsaved* working tree: the app validates and stashes it (cache-only,
TTL-bounded) and the whole session overlays it over the draft — the
`/_preview/{token}` stage, the entry's canonical URL, and the homepage when
the entry is the configured homepage entry. Version-pinned previews are never
overlaid.

Prose blocks (the exactly-one-rich-text convention) are also editable
in-place: annotated renders wrap the sanitized rich-field output in a
`.lemma-edit-region` marker (emitted by `safe_html` itself, only for prose
blocks), and double-clicking one in the canvas turns it into a plain
contenteditable whose text flows back to the admin's block tree. Typed HTML
is sanitized at save and re-sanitized by `safe_html` at render.
While a rich session is active, selecting text shows a small formatting
bubble over the selection (bold, italic, underline, strikethrough,
link/unlink — buttons light up when the caret already carries the mark);
the bridge normalizes ALL rich-region output (bar actions,
native Cmd+B/Cmd+I, paste) into the sanitizer's allowlist shape
(`strong`/`em`/`u`/`s`, no styled spans) before it
flows back, so formatting survives save and re-render. Links are added
through an inline panel in the bubble (TipTap-style, no browser prompt);
URLs are validated against the safe_url posture before they're applied,
and the edit session survives focus moving into the panel.

Applies are automatic by default: the admin re-applies the working tree on a
short debounce after edits (suppressed while typing in-place) and restores
the stage's scroll position across reloads. An Auto toggle beside Apply
turns this off per browser; failures pause it until a manual Apply succeeds.
Successful applies update the stage in place when the change is provably
confined to block wrappers (a real re-render is fetched and compared —
never a client-side guess); anything else, including added or removed
blocks and theme-shell changes, falls back to a full reload.

Plain string/text fields join in via the opt-in `|editable_text` filter:
`{{ data.heading|editable_text('heading') }}` marks the value's rendered
location (annotated renders only; live output is byte-identical to the plain
emission). Apply it ONLY to whole-element text emissions — never inside HTML
attributes (`alt`, `href`), where it would emit broken markup in preview —
and keep existing `{% if %}` guards: a conditionally omitted field stays
inspector-first. The admin validates every edit against the block schema, so
a mistyped field name simply never becomes editable.

**Changing block schemas:** additive edits (new fields, retypes) are free via
`PATCH /block-types/{slug}`; renaming or deleting a field is a declared migration
(`POST /block-types/{slug}/migrations` with `{ops:[{op:"rename",from,to}|{op:"delete",name}]}`)
— the schema flips immediately and a queued backfill rewrites every current draft
and publication (saves/publishes of entries containing the type 409 until it
completes; re-drive a failed run with `php glueful lemma:blocks:migration:backfill
{uuid}`). Version rollback re-projects block data through migrations that postdate
the version. An UNUSED type can be hard-deleted (`DELETE /block-types/{slug}`;
usage via `GET /block-types/{slug}/usage`) — deactivation remains the everyday
path.

## Rich HTML in templates

`format: rich` text fields are sanitized SERVER-SIDE on save (TipTap-scoped
allowlist — no scripts, event handlers, unsafe schemes, images, or tables) and
templates render them with `{{ value|safe_html }}`, which re-sanitizes at output
(defense-in-depth) and falls back to escaped text if no sanitizer is bound. Never
use `|raw` on content fields.

## Facet counts in templates

`facets('post', 'category', limit = 100)` returns `[{uuid, slug, count}, …]` (count
DESC) for filterable reference fields — `[]` on any gate failure, so templates never
break. Pages using it are automatically tagged with both type tags, so counts purge
event-driven like everything else.

## Config

| Key (env) | Default |
|---|---|
| `lemma_render.theme` (`RENDER_THEME`) | `default` |
| `lemma_render.homepage_entry` (`RENDER_HOMEPAGE_ENTRY`) | `''` |
| `lemma_render.site_name` (`RENDER_SITE_NAME`) | `Lemma` |
| `lemma_render.reserved_prefixes` | `v1, admin, extensions, theme-assets` |
| `lemma_render.reserved_exact` | `sitemap.xml, robots.txt` |
| `lemma_render.cache_enabled` (`RENDER_CACHE_ENABLED`) | `true` |
| `lemma_render.cache_ttl` (`RENDER_CACHE_TTL`) | `3600` |

Page views are deliberately not rate-limited (this is the whole-site surface, not an
API); the abuse posture is the page cache below — bogus paths can neither fill the
cache nor re-render templates.

## Page caching

Rendered pages are cached full-page in the framework `CacheStore`, keyed
`render:{theme}:{normalizedPath}`. Only `200` responses with `Content-Type:
text/html` are cached per path. The themed 404/410 bodies are stored once per
theme (`render:{theme}:404` / `render:{theme}:410`) and checked BEFORE the
template renders, so unique bogus URLs cost only the resolver's indexed queries —
they can neither fill the cache nor re-render `404.twig`. Redirects, JSON
responses, and errors are never cached.

| Env | Default | Meaning |
|---|---|---|
| `RENDER_CACHE_ENABLED` | `true` | `false` = uncached SSR (set in dev while theming) |
| `RENDER_CACHE_TTL` | `3600` | safety-net TTL per entry; tags do the real invalidation |

**Invalidation.** Every cached page is tagged with the same surrogate keys the
delivery API uses (`lemma:entry:{uuid}`, `lemma:type:{slug}`) plus
`lemma:render:page`. Publishing, unpublishing, or deleting content purges the
affected pages through the engine's existing cache listener — no render-specific
wiring. Menu changes (`MenuUpdated`) purge every cached page. Theme FILE edits
are not event-visible: run `php glueful render:cache:clear` (or wait out the
TTL). A theme NAME switch needs no purge — the theme is part of the key.

**Non-tag cache drivers.** If the configured cache driver does not support tags
(e.g. the file driver), targeted purges become no-ops and the page cache
degrades to TTL-only freshness: entries still store and expire by
`RENDER_CACHE_TTL`, and `php glueful render:cache:clear` remains the manual
escape hatch. A tag-capable driver (Redis) is recommended for production render
caching.

## Install / remove

Bundled by default in the Lemma create-project template. Existing app:
`composer require glueful/lemma-render`, `./lemma extensions:enable lemma-render`.
Disable via the switchboard (`'capabilities' => ['lemma.render' => false]`) or remove —
the headless product is untouched.

## Out of scope (v1 — see V2_DESIGN §6)

Taxonomy term INDEX pages (`/{type}/{field}` enumerating all terms), DB-edited
templates, page/block builder, admin theme/homepage switching UI, full-site preview
navigation (links on a preview page lead to published pages). Per-page TTL overrides
and stale-while-revalidate are deferred with them (render caching spec §8).
