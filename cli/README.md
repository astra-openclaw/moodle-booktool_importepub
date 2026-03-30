# CLI Tools (Optional)

These Node.js scripts are **not part of the Moodle plugin**. They are standalone pre-processing tools for fixed-layout EPUBs that use CSS-positioned text overlays (e.g. McGraw-Hill Inspire Science).

Standard EPUBs do not need these tools.

## Setup

```bash
npm install playwright
npx playwright install chromium
```

## Usage

### flatten-epub.js

Takes an EPUB with CSS text overlays and produces a new EPUB where each page is a fully-rendered composite image (background + text + fonts).

```bash
node flatten-epub.js input.epub [output.epub] [--quality 90] [--concurrency 4] [--scale 2]
```

- `--quality` — JPEG quality (default: 90)
- `--concurrency` — parallel browser tabs (default: 4)
- `--scale` — device pixel ratio for rendering (default: 2, produces 2× resolution)
- If output is omitted, writes to `<input>-flat.epub`

### render-pages.js

Lower-level tool that renders individual XHTML pages to JPGs. Used internally by `flatten-epub.js`.
