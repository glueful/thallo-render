# Cascade resolver fixtures, v1

The compatibility contract between the PHP resolver (`Thallo\Contracts\Style\CascadeResolver`) and
the TypeScript resolver (`admin/src/style/resolver.ts`). Both runtimes load every `*.json` file
here and must produce byte-equivalent normalised output; drift is a compatibility bug (visual
builder spec §3.3). Versioned with the settings schema: a new schema version gets a new folder.

Each file:

```json
{
  "name": "…",
  "cases": [
    {
      "name": "…",
      "property": "spacing.padding.top",
      "classes": [{ "id": "c1", "style": { … } }],
      "instance": { … },
      "expect": { "base": { "value": …, "source": "instance", "state": "explicit" }, "md": …, "lg": … }
    }
  ]
}
```

`source` is `instance`, `class:<id>` or `theme-default`; `state` is `explicit`, `inherited`,
`theme-default` or `reset`. A non-responsive property expects a single `base` entry. `value` is
`null` when the state is `theme-default` or `reset` (the theme's own rules apply).
