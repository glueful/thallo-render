# Detach fixtures, v1

The compatibility contract between the PHP detach transformation
(`Thallo\Contracts\Style\DetachTransformation`) and the TypeScript twin
(`admin/src/style/detach.ts`), visual builder spec §4.4: removing a style class reference
while preserving every managed effective value at every breakpoint. Both runtimes load every
`*.json` file here and must produce byte-equivalent normalised output. Versioned with the
settings schema: a new schema version gets a new folder.

Each file:

```json
{
  "name": "…",
  "cases": [
    {
      "name": "…",
      "capabilities": ["spacing"],
      "classes": [{ "id": "c1", "style": { … } }],
      "instance": { … },
      "detach": "c1",
      "expect": { … }
    }
  ]
}
```

`capabilities` is the block's declaration (paths or groups, as `style_capabilities`);
`classes` is the block's ordered list; `instance` is `settings.style` before; `detach` the class
id removed; `expect` is `settings.style` after — the instance with the previous resolution
written at every breakpoint whose outcome would otherwise change (`reset` where the previous
outcome was a reset), normalised with empty maps removed. A class id the block does not carry
leaves the instance unchanged.
