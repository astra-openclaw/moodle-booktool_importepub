# Lucimoo EPUB Import for Moodle 5.x

Import EPUB ebooks into Moodle Book resources. Supports both reflowable and fixed-layout EPUBs.

**Fork of [HaakonME/moodle-booktool_importepub](https://github.com/HaakonME/moodle-booktool_importepub)**, originally by Mikael Ylikoski (Ordbrand). Modernized for Moodle 5.x with a complete rewrite of the import pipeline.

## Requirements

- **Moodle 5.0+** (tested on 5.1.3)
- PHP 8.1+
- PHP `zip` extension

## Installation

### Via Git

```bash
cd /path/to/moodle/mod/book/tool
git clone https://github.com/astra-openclaw/moodle-booktool_importepub.git importepub
```

### Manual Download

1. Download the ZIP from GitHub
2. Extract to `mod/book/tool/epubimport/`
3. Visit **Site Administration → Notifications** to complete the install

### Via Moodle Plugin Directory

Not yet listed. Use one of the methods above.

## Usage

1. Navigate to any Book activity (or create one)
2. Click **Import EPUB** in the Book administration menu
3. Upload an `.epub` file
4. Choose import mode:
   - **Add chapters** — appends EPUB content to the book
   - **Replace chapters** — removes existing chapters first

The plugin auto-detects EPUB layout:
- **Reflowable** — imports text content as editable Moodle chapters
- **Fixed-layout** — imports page images (one image per chapter)

## Advanced: CSS Text Overlay EPUBs

Some publishers (e.g. McGraw-Hill) produce fixed-layout EPUBs where the page image is only the graphical layer and text is rendered via CSS. These need pre-processing before import.

The `cli/` directory contains optional Node.js tools for this:

```bash
cd cli/
npm install playwright
npx playwright install chromium

# Pre-render all pages to composite JPGs
node flatten-epub.js input.epub output-flat.epub --quality 90 --scale 2
```

This is **not required** for standard EPUBs. The tools live outside the Moodle plugin's execution path and have no effect on normal operation.

## Architecture

| Class | File | Purpose |
|-------|------|---------|
| `epub_parser` | `classes/epub_parser.php` | EPUB extraction, OPF/spine/TOC parsing, layout detection |
| `toc_mapper` | `classes/toc_mapper.php` | Chapter/subchapter mapping, TOC-to-spine alignment |
| `reflowable_importer` | `classes/reflowable_importer.php` | TOC-driven chapter splitting, CSS scoping, image import |
| `fixed_layout_importer` | `classes/fixed_layout_importer.php` | Page image extraction, background detection |

### Security

- XXE mitigation via `libxml_disable_entity_loader(true)` + `LIBXML_NONET`
- File paths validated against extracted EPUB boundary
- HTML sanitized through Moodle's `clean_text()` pipeline

## Running Tests

```bash
cd /path/to/moodle
vendor/bin/phpunit --testsuite booktool_importepub
```

## License

GNU GPL v3 or later — see [LICENSE](LICENSE).

## Credits

- Original author: Mikael Ylikoski (Ordbrand)
- Moodle 5.x modernization: Robert Q. Watson
