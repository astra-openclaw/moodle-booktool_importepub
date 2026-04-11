# Changes

## 2.0.0-beta2 (2026-04-11)

Follow-up fixes and release preparation for Moodle plugin review and re-submission.

### Changed
- Increased the plugin build number to `2026041100` and updated the release label to `2.0.0-beta2` so the revised package can be submitted as a new review candidate.
- Standardized the plugin component naming as `booktool_epubimport` throughout the modernized codebase so metadata, packaging, and plugin-directory references are consistent.
- Refined the Moodle 5.x cleanup work around the rewritten importer so the package, metadata, and validation story are clearer for review.

### Fixed
- Hardened EPUB parser temporary-directory setup to make extraction and intermediate-file handling safer and more reliable during import.
- Fixed remaining CI, phpdoc, and automated test issues discovered during review preparation, reducing avoidable validation noise.
- Updated the Moodle Plugin CI workflow so the modernized codebase is validated with the current project layout and checks.

## 2.0.0 (2026-03-30)

Complete rewrite for Moodle 5.x compatibility.

### Added
- Modular OOP architecture: `epub_parser`, `toc_mapper`, `reflowable_importer`, `fixed_layout_importer`
- Fixed-layout EPUB support (page-image based books)
- Dual import workflow: create new book or replace existing chapters
- Privacy API provider (GDPR compliance)
- PHPUnit tests for parser and TOC mapper with fixture EPUBs
- XXE mitigation (`libxml_disable_entity_loader` + `LIBXML_NONET`)
- Optional Playwright-based pre-renderer for CSS text overlay EPUBs (see `cli/`)

### Changed
- Replaced monolithic `locallib.php` with namespaced classes under `classes/`
- Minimum requirement: Moodle 5.0+ / PHP 8.1+
- Replaced deprecated `print_error()` with `moodle_exception`
- Updated capability definitions for current Moodle API

### Removed
- Bundled CSS parser library (no longer needed)
- Legacy `add.php` / `add_form.php` entry points (consolidated into `index.php`)
- Non-English language files (need retranslation for new strings)

## 1.1 (2018-02-01)

- Original release by Mikael Ylikoski (Ordbrand)
- Forked by HaakonME
