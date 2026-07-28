# Figtree — provenance

Upstream: https://github.com/erikdkennedy/figtree, tag v2.0.3 (SIL OFL 1.1 — OFL.txt).

| shipped file | upstream source | upstream sha256 | shipped sha256 |
|---|---|---|---|
| figtree-roman-latin.woff2 | fonts/variable/Figtree[wght].ttf | 6d7fb937ffd4ec82f3030857d910846c0d510eb1a0cb5b23f832a980de39c305 | 250f29574dc636e1d1836cd6337dc8bf2ff80462e1cccd34d428a73130b76af3 |
| figtree-italic-latin.woff2 | fonts/variable/Figtree-Italic[wght].ttf | 1a7657e21406f424f2d750d647c90dd122bdf401ae693601e1bda8de45a1731b | cfb2cb2b27cbb3c565642065622366be726a61e6374de716d8e830e200c780e8 |

Subsetting (reproducible; output bytes depend on BOTH tool versions):
- fonttools 4.60.2, brotli 1.2.0 (pip)
- command, run per source file (quotes mandatory — `[…]` is a shell glob):

    pyftsubset '<source>' \
      --unicodes="U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD" \
      --layout-features='*' --flavor=woff2 --output-file=<shipped>

Latin subset only (the standard Google Fonts latin range). Additional subsets follow the
same discipline (default-theme-font spec §9).
