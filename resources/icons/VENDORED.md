# Vendored icon sets

| Set | Upstream | Version | Files | License |
| --- | --- | --- | --- | --- |
| `lucide/` | https://github.com/lucide-icons/lucide (`icons/*.svg`) | 1.23.0 | full set (1,745), byte-identical | ISC |
| `brands/` | https://github.com/simple-icons/simple-icons (`icons/*.svg`) | 16.24.1 | curated subset (27), normalized (below) | CC0 |

## Brand normalization rule (reapply on every refresh)

Simple Icons ships single-path SVGs with NO fill attribute (fixed black by SVG
default) — brand colors exist only as package metadata. Each curated file gets
`fill="currentColor"` on the root `<svg>` and must carry no other fixed
`fill`/`stroke` values:

    perl -pi -e 's/<svg (?![^>]*fill=)/<svg fill="currentColor" /' *.svg

**Exact brand color is theme CSS, not the SVG asset** — a theme wanting
GitHub-black or Spotify-green sets `color` on the element. Brand marks remain
trademarks of their owners; usage responsibility sits with the site operator.

## Curated brands

github
gitlab
bitbucket
google
apple
x
facebook
instagram
youtube
tiktok
discord
whatsapp
telegram
reddit
pinterest
twitch
spotify
snapchat
threads
bluesky
mastodon
vimeo
medium
dribbble
behance
figma
stackoverflow

Deliberately absent (requested at curation, missing upstream): `linkedin`,
`slack` and `microsoft` were removed from Simple Icons at the brand owners'
request and are not available to vendor. Themes needing them must source the
marks themselves under the brands' own guidelines.

## Refresh procedure

1. Download the new pinned release tarballs; replace `lucide/` wholesale,
   re-copy the curated brand slugs (the import FAILS on any missing slug —
   removing a brand is a deliberate edit to this list, the spec, and the
   import list together), re-run the normalization rule.
2. Security review (regression-tested in `IconAssetsTest`): no `<script`,
   no ` on*=` attributes, no `href="http`, no `<foreignObject`.
3. Update the version table above.
