# Lucimoo EPUB Import for Moodle 5.x

Import EPUB ebooks into Moodle Book resources. Supports both reflowable and fixed-layout EPUBs, including textbooks with CSS-positioned text overlays (e.g. McGraw-Hill Inspire Science).

**Fork of [HaakonME/moodle-booktool_importepub](https://github.com/HaakonME/moodle-booktool_importepub)**, originally by Mikael Ylikoski (Ordbrand). Modernized for Moodle 5.x with a complete rewrite of the import pipeline.

## Requirements

- **Moodle 5.0+** (tested on 5.1.3)
- PHP 8.1+
- PHP `zip` extension

For fixed-layout EPUBs with CSS text overlays (pre-rendering):
- Node.js 18+
- Playwright with Chromium (`npx playwright install chromium`)

## Installation

### Via Git (recommended for development)

```bash
cd /path/to/moodle/mod/book/tool
git clone https://github.com/astra-openclaw/moodle-booktool_importepub.git importepub
```

### Manual

1. Download and extract to `mod/book/tool/importepub/`
2. Visit Site Administration → Notifications to complete installation

## Usage

1. Navigate to any Book activity
2. Click the **Import EPUB** link in the Book administration menu
3. Upload an EPUB file
4. Choose import mode:
   - **Create new book** — creates a fresh book with the EPUB contents
   - **Replace existing** — replaces chapters in the current book

### Fixed-Layout EPUBs with CSS Text Overlays

Some publishers (e.g. McGraw-Hill) use a hybrid rendering model where page images contain only graphics and text is rendered via CSS-positioned elements with custom fonts. These EPUBs need pre-processing before import.

**Pre-render with Playwright:**

```bash
# Install dependencies (one-time)
npm install playwright
npx playwright install chromium

# Flatten an EPUB (renders all pages to composite JPGs)
node flatten-epub.js input.epub output-flat.epub --quality 90 --scale 2
```

The `--scale 2` flag renders at 2× resolution (1224×1566 for standard 612×783 pages). The flattened EPUB can then be imported normally.

## Architecture

The plugin is organized into four core classes:

| Class | Purpose |
|-------|---------|
| `epub_parser` | EPUB extraction, OPF/spine/TOC parsing, layout detection |
| `toc_mapper` | Chapter/subchapter mapping, TOC-to-spine alignment |
| `reflowable_importer` | TOC-driven chapter splitting, CSS scoping, image/media import |
| `fixed_layout_importer` | Page image extraction, background detection, popup handling |

### Security

- XXE mitigation via `libxml_disable_entity_loader(true)` + `LIBXML_NONET`
- All file paths validated against the extracted EPUB boundary
- HTML sanitized through Moodle's `clean_text()` pipeline

## What's New in 2.0

- **Complete rewrite** — modular OOP architecture replacing the monolithic `locallib.php`
- **Moodle 5.x compatibility** — updated for current Moodle APIs and coding standards
- **Fixed-layout support** — dedicated importer for page-image EPUBs
- **CSS text overlay rendering** — Playwright-based pre-processor for hybrid-layout textbooks
- **Dual import workflow** — create new books or replace existing content
- **Privacy API** — GDPR-compliant privacy provider
- **PHPUnit tests** — test coverage for parser and TOC mapper

## License

GNU GPL v3 or later. See [LICENSE](http://www.gnu.org/copyleft/gpl.html).

## Credits

- Original author: Mikael Ylikoski (Ordbrand)
- Moodle 5.x modernization: Astra (OpenClaw)
