<?php
// This file is part of Lucimoo
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace booktool_epubimport;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * Imports fixed-layout EPUB page images into Moodle Book chapters.
 *
 * @package    booktool_epubimport
 * @copyright  2013-2018 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fixed_layout_importer {
    /** @var int XML parsing flags mandated for XHTML parsing. */
    private const XML_FLAGS = LIBXML_NONET | LIBXML_NOENT;

    /** @var epub_parser Parsed EPUB package. */
    private epub_parser $parser;

    /** @var toc_mapper Fixed-layout chapter mapper. */
    private toc_mapper $mapper;

    /** @var object Moodle Book record. */
    private object $book;

    /** @var object Module context record. */
    private object $context;

    /** @var string Extraction directory for the EPUB package. */
    private string $tempdir;

    /** @var array<int, array<string, string>> Parsed OPF spine items. */
    private array $spine;

    /** @var array<string, string> Extracted files keyed by EPUB-root-relative path. */
    private array $filesbyrootpath = [];

    /**
     * @var array<string, array<int, string>> Extracted root-relative paths keyed by basename.
     */
    private array $filesbyname = [];

    /**
     * Constructor.
     *
     * @param epub_parser $parser Extracted EPUB parser.
     * @param toc_mapper $mapper TOC-to-chapter mapper.
     * @param object $book Moodle Book record.
     * @param object $context Module context instance.
     */
    public function __construct(epub_parser $parser, toc_mapper $mapper, object $book, object $context) {
        if (!isset($book->id) || (int)$book->id <= 0) {
            throw new InvalidArgumentException('Book record must include a positive id.');
        }

        if (!isset($context->id) || (int)$context->id <= 0) {
            throw new InvalidArgumentException('Context record must include a positive id.');
        }

        $this->parser = $parser;
        $this->mapper = $mapper;
        $this->book = $book;
        $this->context = $context;
        $this->tempdir = rtrim($this->parser->get_tempdir(), DIRECTORY_SEPARATOR);
        $this->spine = $this->parser->get_spine();
    }

    /**
     * Imports the mapped fixed-layout chapter plan into Moodle Book chapters.
     *
     * @return array Created chapter records.
     */
    public function import(): array {
        global $DB;

        $plan = $this->mapper->get_chapter_plan();
        if ($plan === []) {
            return [];
        }

        $this->build_file_lookups();

        $nextpagenum = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(pagenum), 0) FROM {book_chapters} WHERE bookid = ?',
            [$this->book->id]
        ) + 1;

        $created = [];

        foreach ($plan as $planentry) {
            $prepared = $this->prepare_chapter($planentry);
            $chapter = $this->create_chapter_record(
                $prepared['title'],
                $prepared['content'],
                $prepared['subchapter'],
                $nextpagenum
            );

            $this->store_chapter_assets($chapter, $prepared['assets']);

            $chapter = $DB->get_record('book_chapters', ['id' => $chapter->id], '*', MUST_EXIST);
            \mod_book\event\chapter_created::create_from_chapter($this->book, $this->context, $chapter)->trigger();

            $created[] = $chapter;
            $nextpagenum++;
        }

        return $created;
    }

    /**
     * Builds the rendered HTML and file list for one mapped chapter range.
     *
     * @param array $planentry Mapped chapter plan entry.
     * @return array Prepared chapter content and asset descriptors.
     */
    private function prepare_chapter(array $planentry): array {
        $title = $this->chapter_title($planentry);
        $assetmap = [];
        $htmlparts = [];

        for ($index = (int)$planentry['spine_start']; $index < (int)$planentry['spine_end']; $index++) {
            if (!isset($this->spine[$index])) {
                continue;
            }

            $page = $this->prepare_page($this->spine[$index]);
            if ($page['background'] !== null) {
                $backgroundfilename = $this->register_asset(
                    $assetmap,
                    $page['background']['full_path'],
                    $page['background']['filename']
                );
                $htmlparts[] = '<!-- Page ' . $page['page_number'] . ' -->';
                $htmlparts[] =
                    '<div class="epub-page">' .
                    '<img src="@@PLUGINFILE@@/' . s($backgroundfilename) . '" alt="Page ' . $page['page_number'] . '"' .
                    ' style="max-width:100%; height:auto;" />' .
                    '</div>';
            }

            foreach ($page['popups'] as $popup) {
                $popupfilename = $this->register_asset($assetmap, $popup['full_path'], $popup['filename']);
                $htmlparts[] = '<!-- Popup content from page ' . $page['page_number'] . ' -->';
                $htmlparts[] =
                    '<div class="epub-popup">' .
                    '<img src="@@PLUGINFILE@@/' . s($popupfilename) . '" alt="Interactive content"' .
                    ' style="max-width:100%; height:auto;" />' .
                    '</div>';
            }
        }

        if ($htmlparts === []) {
            throw new RuntimeException('No fixed-layout page images were found for chapter "' . $title . '".');
        }

        $assets = [];
        foreach ($assetmap as $filename => $sourcepath) {
            $assets[] = [
                'source_path' => $sourcepath,
                'filename' => $filename,
            ];
        }

        return [
            'title' => $title,
            'subchapter' => !empty($planentry['subchapter']),
            'content' => implode("\n", $htmlparts),
            'assets' => $assets,
        ];
    }

    /**
     * Prepares the background image and popup assets for a single XHTML spine item.
     *
     * @param array $spineitem Parsed OPF spine item.
     * @return array Page background and popup asset details.
     */
    private function prepare_page(array $spineitem): array {
        $href = trim((string)($spineitem['href'] ?? ''));
        $resolvedxhtml = $this->resolve_package_file($href);

        if ($resolvedxhtml === null) {
            throw new RuntimeException('Unable to resolve fixed-layout page XHTML: ' . $href);
        }

        $document = $this->load_xml_file($resolvedxhtml['full_path'], 'page XHTML "' . $href . '"');
        $xpath = new DOMXPath($document);

        $this->remove_page_container($xpath);

        $bodyimage = $this->find_first_element($xpath, '//*[local-name()="div" and @id="bodyimage"]');
        $stylesheets = $this->load_stylesheets($xpath, $resolvedxhtml['root_path']);
        $background = $this->detect_background_image($bodyimage, $xpath, $stylesheets, $resolvedxhtml['root_path']);
        $popups = $this->extract_popup_images($xpath, $stylesheets, $resolvedxhtml['root_path']);

        return [
            'page_number' => $this->page_number_from_path($resolvedxhtml['root_path']),
            'background' => $background,
            'popups' => $popups,
        ];
    }

    /**
     * Creates and inserts a Moodle Book chapter record.
     *
     * @param string $title Title.
     * @param string $content Content.
     * @param bool $subchapter Subchapter.
     * @param int $pagenum Pagenum.
     */
    private function create_chapter_record(string $title, string $content, bool $subchapter, int $pagenum): stdClass {
        global $DB;

        $timestamp = time();

        $chapter = new stdClass();
        $chapter->bookid = (int)$this->book->id;
        $chapter->pagenum = $pagenum;
        $chapter->subchapter = $subchapter ? 1 : 0;
        $chapter->title = $title;
        $chapter->content = $content;
        $chapter->contentformat = FORMAT_HTML;
        $chapter->hidden = 0;
        $chapter->timecreated = $timestamp;
        $chapter->timemodified = $timestamp;
        $chapter->id = $DB->insert_record('book_chapters', $chapter);

        return $chapter;
    }

    /**
     * Stores page and popup image files into the Moodle chapter file area.
     *
     * @param stdClass $chapter Inserted chapter record.
     * @param array $assets Chapter asset descriptors.
     */
    private function store_chapter_assets(stdClass $chapter, array $assets): void {
        $fs = get_file_storage();

        foreach ($assets as $asset) {
            $filepath = $asset['source_path'];
            if (!is_file($filepath)) {
                throw new RuntimeException('Unable to store missing chapter asset: ' . $filepath);
            }

            $filename = $asset['filename'];
            if ($fs->file_exists($this->context->id, 'mod_book', 'chapter', $chapter->id, '/', $filename)) {
                continue;
            }

            $filerecord = [
                'contextid' => $this->context->id,
                'component' => 'mod_book',
                'filearea' => 'chapter',
                'itemid' => $chapter->id,
                'filepath' => '/',
                'filename' => $filename,
            ];

            $fs->create_file_from_pathname($filerecord, $filepath);
        }
    }

    /**
     * Builds absolute file lookups for the extracted EPUB package.
     */
    private function build_file_lookups(): void {
        if ($this->filesbyrootpath !== []) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempdir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileinfo) {
            if (!$fileinfo instanceof \SplFileInfo || !$fileinfo->isFile()) {
                continue;
            }

            $fullpath = $fileinfo->getPathname();
            $rootpath = str_replace('\\', '/', substr($fullpath, strlen($this->tempdir) + 1));

            $this->filesbyrootpath[$rootpath] = $fullpath;
            $filename = basename($rootpath);
            $this->filesbyname[$filename][] = $rootpath;
        }

        foreach ($this->filesbyname as &$paths) {
            sort($paths);
        }
        unset($paths);
    }

    /**
     * Loads stylesheet text linked from a page XHTML document.
     *
     * @param DOMXPath $xpath Page XPath helper.
     * @param string $xhtmlrootpath Root-relative path of the page XHTML file.
     * @return array Parsed CSS rules.
     */
    private function load_stylesheets(DOMXPath $xpath, string $xhtmlrootpath): array {
        $rules = [];
        $links = $xpath->query('//*[local-name()="link"][@href]');

        if ($links !== false) {
            foreach ($links as $link) {
                if (!$link instanceof DOMElement) {
                    continue;
                }

                $rel = strtolower(trim($link->getAttribute('rel')));
                if ($rel !== '' && !str_contains($rel, 'stylesheet')) {
                    continue;
                }

                $href = trim($link->getAttribute('href'));
                $resolved = $this->resolve_package_file($href, $xhtmlrootpath);
                if ($resolved === null) {
                    continue;
                }

                $css = file_get_contents($resolved['full_path']);
                if ($css === false) {
                    throw new RuntimeException('Unable to read stylesheet: ' . $resolved['root_path']);
                }

                $rules = array_merge($rules, $this->parse_css_rules($css, $resolved['root_path']));
            }
        }

        $stylenodes = $xpath->query('//*[local-name()="style"]');
        if ($stylenodes !== false) {
            foreach ($stylenodes as $style) {
                $css = trim($style->textContent ?? '');
                if ($css === '') {
                    continue;
                }

                $rules = array_merge($rules, $this->parse_css_rules($css, $xhtmlrootpath));
            }
        }

        return $rules;
    }

    /**
     * Parses CSS into simple selector/declaration rules.
     *
     * @param string $css Raw CSS text.
     * @param string $sourcepath Root-relative path of the CSS source file.
     * @return array Parsed CSS rules.
     */
    private function parse_css_rules(string $css, string $sourcepath): array {
        $css = preg_replace('!/\*.*?\*/!s', '', $css);
        if ($css === null || trim($css) === '') {
            return [];
        }

        $rules = [];
        if (!preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER)) {
            return $rules;
        }

        foreach ($matches as $match) {
            $selectors = array_values(
                array_filter(
                    array_map('trim', explode(',', trim($match[1]))),
                    static fn(string $selector): bool => $selector !== ''
                )
            );

            $declarations = trim($match[2]);
            if ($selectors === [] || $declarations === '') {
                continue;
            }

            $rules[] = [
                'selectors' => $selectors,
                'declarations' => $declarations,
                'source_root_path' => $sourcepath,
            ];
        }

        return $rules;
    }

    /**
     * Detects the main page image from CSS, inline styles, visible images, or filename convention.
     *
     * @param DOMElement|null $bodyimage Body image container element.
     * @param DOMXPath $xpath Page XPath helper.
     * @param array $stylesheets Parsed CSS rules.
     * @param string $xhtmlrootpath Root-relative path of the page XHTML file.
     * @return array|null Background asset descriptor, or null when none is found.
     */
    private function detect_background_image(
        ?DOMElement $bodyimage,
        DOMXPath $xpath,
        array $stylesheets,
        string $xhtmlrootpath
    ): ?array {
        if ($bodyimage !== null) {
            $inlinestyle = trim($bodyimage->getAttribute('style'));
            $inlineurl = $this->extract_css_url($inlinestyle, ['background-image', 'background']);
            if ($inlineurl !== '') {
                $resolved = $this->resolve_package_file($inlineurl, $xhtmlrootpath);
                if ($resolved !== null) {
                    return $this->asset_descriptor($resolved);
                }
            }
        }

        if ($bodyimage !== null) {
            foreach ($stylesheets as $rule) {
                foreach ($rule['selectors'] as $selector) {
                    if (!$this->selector_matches_element($selector, $bodyimage)) {
                        continue;
                    }

                    $url = $this->extract_css_url($rule['declarations'], ['background-image', 'background']);
                    if ($url === '') {
                        continue;
                    }

                    $resolved = $this->resolve_package_file($url, $rule['source_root_path']);
                    if ($resolved !== null) {
                        return $this->asset_descriptor($resolved);
                    }
                }
            }
        }

        $images = $xpath->query('//*[local-name()="img"][@src]');
        if ($images !== false) {
            foreach ($images as $image) {
                if (!$image instanceof DOMElement || $this->is_popup_image($image, $stylesheets)) {
                    continue;
                }

                $resolved = $this->resolve_package_file(trim($image->getAttribute('src')), $xhtmlrootpath);
                if ($resolved !== null) {
                    return $this->asset_descriptor($resolved);
                }
            }
        }

        return $this->detect_background_by_filename($xhtmlrootpath);
    }

    /**
     * Falls back to page image filename conventions like images/page0015.jpg.
     *
     * @param string $xhtmlrootpath Root-relative path of the page XHTML file.
     * @return array|null Background asset descriptor, or null when no file matches.
     */
    private function detect_background_by_filename(string $xhtmlrootpath): ?array {
        $basename = pathinfo($xhtmlrootpath, PATHINFO_FILENAME);
        if ($basename === '') {
            return null;
        }

        $candidates = [
            'images/' . $basename . '.jpg',
            'images/' . $basename . '.jpeg',
            'images/' . $basename . '.png',
            'images/' . strtolower($basename) . '.jpg',
            'images/' . strtolower($basename) . '.jpeg',
            'images/' . strtolower($basename) . '.png',
        ];

        foreach ($candidates as $candidate) {
            $resolved = $this->resolve_package_file($candidate, $xhtmlrootpath);
            if ($resolved !== null) {
                return $this->asset_descriptor($resolved);
            }
        }

        $filename = $basename . '.jpg';
        foreach ($this->filesbyname[$filename] ?? [] as $rootpath) {
            return $this->asset_descriptor([
                'root_path' => $rootpath,
                'full_path' => $this->filesbyrootpath[$rootpath],
            ]);
        }

        return null;
    }

    /**
     * Extracts popup image assets from opacity:0 containers.
     *
     * @param DOMXPath $xpath Page XPath helper.
     * @param array $stylesheets Parsed CSS rules.
     * @param string $xhtmlrootpath Root-relative path of the page XHTML file.
     * @return array Popup asset descriptors.
     */
    private function extract_popup_images(DOMXPath $xpath, array $stylesheets, string $xhtmlrootpath): array {
        $popups = [];
        $seen = [];
        $images = $xpath->query('//*[local-name()="img"][@src]');

        if ($images === false) {
            return $popups;
        }

        foreach ($images as $image) {
            if (!$image instanceof DOMElement || !$this->is_popup_image($image, $stylesheets)) {
                continue;
            }

            $resolved = $this->resolve_package_file(trim($image->getAttribute('src')), $xhtmlrootpath);
            if ($resolved === null || isset($seen[$resolved['root_path']])) {
                continue;
            }

            $seen[$resolved['root_path']] = true;
            $popups[] = $this->asset_descriptor($resolved);
        }

        return $popups;
    }

    /**
     * Returns whether an image sits inside an opacity:0 popup container.
     *
     * @param DOMElement $image Image element to inspect.
     * @param array $stylesheets Parsed CSS rules.
     * @return bool
     */
    private function is_popup_image(DOMElement $image, array $stylesheets): bool {
        $node = $image->parentNode;

        while ($node instanceof DOMElement) {
            if ($this->element_has_zero_opacity($node, $stylesheets)) {
                return true;
            }

            $node = $node->parentNode;
        }

        return false;
    }

    /**
     * Returns whether an element has opacity zero via inline style or a matching CSS rule.
     *
     * @param DOMElement $element Element to inspect.
     * @param array $stylesheets Parsed CSS rules.
     * @return bool
     */
    private function element_has_zero_opacity(DOMElement $element, array $stylesheets): bool {
        if ($this->declaration_has_zero_opacity($element->getAttribute('style'))) {
            return true;
        }

        foreach ($stylesheets as $rule) {
            if (!$this->declaration_has_zero_opacity($rule['declarations'])) {
                continue;
            }

            foreach ($rule['selectors'] as $selector) {
                if ($this->selector_matches_element($selector, $element)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns whether a CSS declaration block sets opacity to zero.
     *
     * @param string $declarations Declarations.
     */
    private function declaration_has_zero_opacity(string $declarations): bool {
        return preg_match('/(?:^|[;{])\s*opacity\s*:\s*0(?:\.0+)?\s*(?:;|$)/i', $declarations) === 1;
    }

    /**
     * Extracts the first CSS url() value from a declaration block.
     *
     * @param string $declarations Declaration block or style attribute.
     * @param array $properties Property names to inspect in order.
     * @return string
     */
    private function extract_css_url(string $declarations, array $properties): string {
        foreach ($properties as $property) {
            if (
                preg_match(
                    '/(?:^|[;{])\s*' . preg_quote($property, '/') .
                    '\s*:\s*[^;]*url\((["\']?)([^)"\']+)\1\)/i',
                    $declarations,
                    $matches
                ) === 1
            ) {
                return trim($matches[2]);
            }
        }

        return '';
    }

    /**
     * Returns whether a simple CSS selector matches an element.
     *
     * Supports the selector shapes used by page CSS for this importer:
     * `#id`, `.class`, `tag`, `tag#id`, `tag.class`, and chained class selectors.
     *
     * @param string $selector Selector.
     * @param DOMElement $element Element.
     */
    private function selector_matches_element(string $selector, DOMElement $element): bool {
        $selector = trim($selector);
        if ($selector === '') {
            return false;
        }

        $selector = preg_replace('/::?[a-z0-9_-]+(?:\([^)]*\))?/i', '', $selector);
        $selector = trim((string)$selector);

        if ($selector === '' || strpbrk($selector, ' >+~[') !== false) {
            return false;
        }

        $tagname = '';
        $id = '';
        $classes = [];
        $cursor = $selector;

        if (preg_match('/^[a-z][a-z0-9_-]*/i', $cursor, $tagmatch) === 1) {
            $tagname = strtolower($tagmatch[0]);
            $cursor = substr($cursor, strlen($tagmatch[0]));
        }

        while ($cursor !== '') {
            if (preg_match('/^#([a-z0-9_-]+)/i', $cursor, $idmatch) === 1) {
                $id = $idmatch[1];
                $cursor = substr($cursor, strlen($idmatch[0]));
                continue;
            }

            if (preg_match('/^\.([a-z0-9_-]+)/i', $cursor, $classmatch) === 1) {
                $classes[] = $classmatch[1];
                $cursor = substr($cursor, strlen($classmatch[0]));
                continue;
            }

            return false;
        }

        $elementtag = strtolower($element->localName ?? $element->tagName);
        if ($tagname !== '' && $tagname !== $elementtag) {
            return false;
        }

        if ($id !== '' && $element->getAttribute('id') !== $id) {
            return false;
        }

        if ($classes !== []) {
            $elementclasses = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
            foreach ($classes as $class) {
                if (!in_array($class, $elementclasses, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Removes the invisible positioned word overlay container from the page DOM.
     *
     * @param DOMXPath $xpath Xpath.
     */
    private function remove_page_container(DOMXPath $xpath): void {
        $nodes = $xpath->query('//*[local-name()="div" and @id="PageContainer"]');
        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement || $node->parentNode === null) {
                continue;
            }

            $node->parentNode->removeChild($node);
        }
    }

    /**
     * Returns the first matching element for an XPath query.
     *
     * @param DOMXPath $xpath Xpath.
     * @param string $expression Expression.
     */
    private function find_first_element(DOMXPath $xpath, string $expression): ?DOMElement {
        $nodes = $xpath->query($expression);
        if ($nodes === false || $nodes->length === 0 || !$nodes->item(0) instanceof DOMElement) {
            return null;
        }

        return $nodes->item(0);
    }

    /**
     * Resolves an EPUB path to an extracted file on disk.
     *
     * @param string $path Path or href to resolve.
     * @param string|null $basepath Optional root-relative base document path.
     * @return array|null Resolved root-relative and absolute file paths, or null when unavailable.
     */
    private function resolve_package_file(string $path, ?string $basepath = null): ?array {
        $path = trim($path);
        if ($path === '' || $this->has_uri_scheme($path)) {
            return null;
        }

        [$path] = $this->split_fragment($path);
        $path = $this->normalise_path($path);
        if ($path === '') {
            return null;
        }

        $candidates = [];

        if ($basepath !== null && $basepath !== '') {
            $basedir = $this->directory_name($basepath);
            $candidates[] = $this->normalise_path($basedir === '' ? $path : $basedir . '/' . $path);
        }

        $candidates[] = ltrim($path, '/');

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && isset($this->filesbyrootpath[$candidate])) {
                return [
                    'root_path' => $candidate,
                    'full_path' => $this->filesbyrootpath[$candidate],
                ];
            }
        }

        foreach (array_keys($this->filesbyrootpath) as $rootpath) {
            if (str_ends_with($rootpath, '/' . $path)) {
                return [
                    'root_path' => $rootpath,
                    'full_path' => $this->filesbyrootpath[$rootpath],
                ];
            }
        }

        $filename = basename($path);
        foreach ($this->filesbyname[$filename] ?? [] as $rootpath) {
            return [
                'root_path' => $rootpath,
                'full_path' => $this->filesbyrootpath[$rootpath],
            ];
        }

        return null;
    }

    /**
     * Registers a chapter asset and returns the unique filename used in chapter HTML.
     *
     * @param array $assetmap Map of filename => source path.
     * @param string $sourcepath Absolute filesystem path to the source asset.
     * @param string $filename Preferred filename in the chapter file area.
     * @return string
     */
    private function register_asset(array &$assetmap, string $sourcepath, string $filename): string {
        $filename = basename(trim($filename));
        if ($filename === '') {
            throw new RuntimeException('Chapter asset filename cannot be empty.');
        }

        if (isset($assetmap[$filename])) {
            if ($assetmap[$filename] === $sourcepath) {
                return $filename;
            }

            $pathinfo = pathinfo($filename);
            $stem = $pathinfo['filename'] ?? $filename;
            $extension = isset($pathinfo['extension']) && $pathinfo['extension'] !== ''
                ? '.' . $pathinfo['extension']
                : '';

            $counter = 2;
            do {
                $candidate = $stem . '-' . $counter . $extension;
                $counter++;
            } while (isset($assetmap[$candidate]) && $assetmap[$candidate] !== $sourcepath);

            $filename = $candidate;
        }

        $assetmap[$filename] = $sourcepath;

        return $filename;
    }

    /**
     * Normalises a resolved file into a reusable asset descriptor.
     *
     * @param array $resolved Resolved root-relative and absolute file paths.
     * @return array Asset descriptor including the preferred filename.
     */
    private function asset_descriptor(array $resolved): array {
        return [
            'root_path' => $resolved['root_path'],
            'full_path' => $resolved['full_path'],
            'filename' => basename($resolved['root_path']),
        ];
    }

    /**
     * Loads and parses an XML/XHTML file using the mandated security flags.
     *
     * @param string $filepath Filepath.
     * @param string $label Label.
     */
    private function load_xml_file(string $filepath, string $label): DOMDocument {
        $xml = file_get_contents($filepath);
        if ($xml === false) {
            throw new RuntimeException('Unable to read ' . $label . '.');
        }

        $previouserrors = libxml_use_internal_errors(true);
        $previousloader = null;

        if (PHP_VERSION_ID < 80000) {
            $previousloader = self::disable_xml_entity_loader(true);
        }

        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, self::XML_FLAGS);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previouserrors);

        if (PHP_VERSION_ID < 80000 && $previousloader !== null) {
            self::disable_xml_entity_loader($previousloader);
        }

        if (!$loaded) {
            $messages = array_map(
                static fn($error): string => trim($error->message),
                $errors
            );
            $suffix = $messages === [] ? '' : ' ' . implode(' ', $messages);
            throw new RuntimeException('Unable to parse ' . $label . '.' . $suffix);
        }

        return $document;
    }

    /**
     * Disables libxml entity loading on legacy libxml releases only.
     *
     * @param bool $disable Whether entity loading should be disabled.
     * @return bool Previous entity-loader state for legacy libxml versions.
     */
    private static function disable_xml_entity_loader(bool $disable): bool {
        if (LIBXML_VERSION >= 20900) {
            return true;
        }

        // @codeCoverageIgnoreStart
        // phpcs:ignore moodle.PHP.DeprecatedFunctions.Deprecated -- Needed only for legacy libxml behaviour.
        return libxml_disable_entity_loader($disable);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Returns a readable title for a chapter plan entry.
     *
     * @param array $planentry Mapped chapter plan entry.
     * @return string
     */
    private function chapter_title(array $planentry): string {
        $title = trim((string)($planentry['title'] ?? ''));
        if ($title === '') {
            $title = pathinfo((string)($planentry['start_href'] ?? ''), PATHINFO_FILENAME);
        }

        if ($title === '') {
            $title = 'Untitled Chapter';
        }

        $title = trim((string)preg_replace('/\s+/', ' ', $title));

        return \core_text::substr($title, 0, 255);
    }

    /**
     * Extracts a human-readable page number from a page or image path.
     *
     * @param string $path Path.
     */
    private function page_number_from_path(string $path): int {
        if (preg_match('/(\d+)/', basename($path), $matches) === 1) {
            return (int)$matches[1];
        }

        return 0;
    }

    /**
     * Splits a href into path and fragment components.
     *
     * @param string $href Href to split.
     * @return array Path and fragment components.
     */
    private function split_fragment(string $href): array {
        $parts = explode('#', $href, 2);
        $path = $parts[0];
        $fragment = $parts[1] ?? '';

        return [$path, $fragment];
    }

    /**
     * Normalises a relative EPUB path.
     *
     * @param string $path Path to normalise.
     * @return string
     */
    private function normalise_path(string $path): string {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || $path === '.') {
            return '';
        }

        $absolute = str_starts_with($path, '/');
        $segments = explode('/', $path);
        $stack = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($stack !== [] && end($stack) !== '..') {
                    array_pop($stack);
                    continue;
                }

                if (!$absolute) {
                    $stack[] = '..';
                }

                continue;
            }

            $stack[] = $segment;
        }

        $normalised = implode('/', $stack);

        if ($absolute) {
            return '/' . $normalised;
        }

        return $normalised;
    }

    /**
     * Returns the directory portion of a relative EPUB path.
     *
     * @param string $path Relative path.
     * @return string
     */
    private function directory_name(string $path): string {
        $path = $this->normalise_path($path);
        if ($path === '' || !str_contains($path, '/')) {
            return '';
        }

        $dirname = dirname($path);

        return $dirname === '.' ? '' : str_replace('\\', '/', $dirname);
    }

    /**
     * Returns whether a href starts with a URI scheme.
     *
     * @param string $href Href.
     */
    private function has_uri_scheme(string $href): bool {
        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $href) === 1;
    }

    /**
     * Detects whether the EPUB uses CSS-positioned text overlays on fixed-layout pages.
     *
     * These EPUBs (e.g. McGraw-Hill Inspire Science) use background-image for the graphic
     * layer and absolutely-positioned <span> elements with custom @font-face for text.
     * A browser engine is needed to composite them.
     *
     * Samples up to 3 XHTML spine pages and checks for the characteristic pattern:
     * a #bodyimage div whose CSS declares background-image, combined with a #PageContainer
     * or #parent-* div containing absolutely-positioned text spans.
     *
     * @return bool
     */
    private function has_css_text_overlay(): bool {
        $sampled = 0;
        $hits = 0;

        foreach ($this->spine as $spineitem) {
            $href = trim((string)($spineitem['href'] ?? ''));
            $resolved = $this->resolve_package_file($href);
            if ($resolved === null) {
                continue;
            }

            $content = @file_get_contents($resolved['full_path']);
            if ($content === false) {
                continue;
            }

            // Look for the characteristic pattern: #bodyimage + #PageContainer with text spans.
            $hasbodyimage = str_contains($content, 'id="bodyimage"');
            $haspagecontainer = str_contains($content, 'id="PageContainer"');
            $hastextspans = (bool)preg_match('/<span\s[^>]*class="[^"]*psa[^"]*"/', $content);

            if ($hasbodyimage && $haspagecontainer && $hastextspans) {
                $hits++;
            }

            $sampled++;
            if ($sampled >= 3) {
                break;
            }
        }

        // If most sampled pages have the pattern, assume the whole EPUB needs pre-rendering.
        return $hits >= 2 || ($sampled === 1 && $hits === 1);
    }

    /**
     * Pre-renders all XHTML pages to composite JPG images via the Node.js/Playwright renderer.
     *
     * This replaces the background-only JPGs in images/ with fully-rendered composites that
     * include both the graphic layer and the CSS text overlays.
     *
     * @throws RuntimeException If the renderer fails.
     */
    private function prerender_pages(): void {
        $renderscript = __DIR__ . '/../cli/render-pages.js';
        if (!is_file($renderscript)) {
            throw new RuntimeException(
                'Fixed-layout page renderer not found at: ' . $renderscript .
                '. CSS-text overlay EPUBs require Node.js + Playwright for rendering.'
            );
        }

        // Find the OPS/OEBPS directory within the extracted EPUB.
        $opsdir = null;
        foreach (['OPS', 'OEBPS', 'ops', 'oebps'] as $candidate) {
            $path = $this->tempdir . DIRECTORY_SEPARATOR . $candidate;
            if (is_dir($path)) {
                $opsdir = $candidate;
                break;
            }
        }

        $cmd = sprintf(
            'node %s %s --quality 90 --concurrency 4 --scale 2 2>&1',
            escapeshellarg($renderscript),
            escapeshellarg($this->tempdir)
        );

        $output = [];
        $exitcode = 0;
        exec($cmd, $output, $exitcode);

        $outputtext = implode("\n", $output);

        // Parse summary from output.
        $summaryline = '';
        foreach ($output as $line) {
            if (str_starts_with($line, 'RENDER_SUMMARY:')) {
                $summaryline = substr($line, strlen('RENDER_SUMMARY:'));
                break;
            }
        }

        if ($exitcode !== 0 || $summaryline === '') {
            throw new RuntimeException(
                'Fixed-layout page pre-rendering failed (exit code ' . $exitcode . "): \n" . $outputtext
            );
        }

        $summary = json_decode($summaryline, true);
        if (!is_array($summary) || ($summary['rendered'] ?? 0) === 0) {
            throw new RuntimeException('Page pre-rendering produced no output: ' . $outputtext);
        }

        debugging(
            'Pre-rendered ' . $summary['rendered'] . ' fixed-layout pages via Playwright (' .
            $summary['totalSeconds'] . 's). Errors: ' . ($summary['errors'] ?? 0),
            DEBUG_DEVELOPER
        );
    }
}
