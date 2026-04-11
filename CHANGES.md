# Changes

## 2.0.0-beta2 (2026-04-11)

Follow-up fixes and release prep for Moodle plugin review.

### Changed
- Bumped plugin release metadata for re-submission (`2.0.0-beta2`, build `2026041100`)
- Renamed the component consistently to `booktool_epubimport`
- Polished Moodle 5.x cleanup and validation details across the plugin

### Fixed
- Hardened EPUB parser temporary directory setup
- Fixed remaining CI, phpdoc, and test issues
- Updated the Moodle Plugin CI workflow for the modernized codebase

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
