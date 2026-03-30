<?php
declare(strict_types=1);

namespace booktool_epubimport;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Imports reflowable EPUB XHTML content into Moodle Book chapters.
 */
final class reflowable_importer {
    /** @var int XML parsing flags mandated for XHTML parsing. */
    private const XML_FLAGS = LIBXML_NONET | LIBXML_NOENT;

    /** @var string Empty placeholder content used before final link rewriting. */
    private const PLACEHOLDER_CONTENT = '<div class="lucimoo"></div>';

    /** @var epub_parser Parsed EPUB package. */
    private epub_parser $parser;

    /** @var toc_mapper TOC-to-chapter mapper. */
    private toc_mapper $mapper;

    /** @var object Moodle Book record. */
    private object $book;

    /** @var object Module context record. */
    private object $context;

    /** @var string Extraction directory for the EPUB package. */
    private string $tempdir;

    /**
     * @var array<int, array{id: string, href: string, media_type: string}>
     */
    private array $spine;

    /** @var array<string, string> Extracted files keyed by EPUB-root-relative path. */
    private array $filesbyrootpath = [];

    /**
     * @var array<string, array<int, string>> Extracted root-relative paths keyed by basename.
     */
    private array $filesbyname = [];

    /** @var array<string, bool> Resolved XHTML spine documents keyed by root-relative path. */
    private array $spinepaths = [];

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

        if (!isset($context->instanceid) || (int)$context->instanceid <= 0) {
            throw new InvalidArgumentException('Context record must include a positive instanceid.');
        }

        $this->parser = $parser;
        $this->mapper = $mapper;
        $this->book = $book;
        $this->context = $context;
        $this->tempdir = rtrim($this->parser->get_tempdir(), DIRECTORY_SEPARATOR);
        $this->spine = $this->parser->get_spine();
    }

    /**
     * Imports the mapped reflowable chapter plan into Moodle Book chapters.
     *
     * @return array<int, stdClass> Created chapter records.
     */
    public function import(): array {
        global $DB;

        $plan = $this->mapper->get_chapter_plan();
        if ($plan === []) {
            return [];
        }

        $this->build_file_lookups();
        $this->build_spine_path_lookup();

        $payloads = $this->build_payloads($plan);
        if ($payloads === []) {
            return [];
        }

        $nextpagenum = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(pagenum), 0) FROM {book_chapters} WHERE bookid = ?',
            [$this->book->id]
        ) + 1;

        foreach ($payloads as $index => $payload) {
            $chapter = $this->create_placeholder_chapter(
                $payload['title'],
                $payload['subchapter'],
                $payload['importsrc'],
                $nextpagenum
            );

            $payloads[$index]['chapterid'] = (int)$chapter->id;
            $this->store_chapter_assets($chapter, $payload['assetmap']);
            $nextpagenum++;
        }

        $this->rewrite_internal_links($payloads);

        $created = [];
        foreach ($payloads as $payload) {
            $html = $this->render_payload_html($payload['document']);
            $html = clean_text($html, FORMAT_HTML);
            $this->update_chapter_content((int)$payload['chapterid'], $html);

            $chapter = $DB->get_record('book_chapters', ['id' => $payload['chapterid']], '*', MUST_EXIST);
            \mod_book\event\chapter_created::create_from_chapter($this->book, $this->context, $chapter)->trigger();
            $created[] = $chapter;
        }

        return $created;
    }

    /**
     * Builds the final chapter payloads from the TOC plan.
     *
     * @param array<int, array{
     *     title: string,
     *     subchapter: bool,
     *     start_href: string,
     *     end_href: string,
     *     spine_start: int,
     *     spine_end: int,
     *     toc_level: int
     * }> $plan
     * @return array<int, array{
     *     title: string,
     *     subchapter: bool,
     *     importsrc: string,
     *     document: DOMDocument,
     *     assetmap: array<string, string>,
     *     sourceanchors: array<string, string>,
     *     fragmenttargets: array<string, string>,
     *     chapterid: int
     * }>
     */
    private function build_payloads(array $plan): array {
        $payloads = [];
        $useheadingfallback = $this->should_use_heading_fallback();

        foreach ($plan as $planentry) {
            $payload = $this->prepare_payload($planentry);
            if ($useheadingfallback) {
                foreach ($this->split_payload_by_headings($payload) as $splitpayload) {
                    $payloads[] = $splitpayload;
                }
                continue;
            }

            $payloads[] = $payload;
        }

        return $payloads;
    }

    /**
     * Builds a single TOC-driven chapter payload from a mapped spine range.
     *
     * @param array{
     *     title: string,
     *     subchapter: bool,
     *     start_href: string,
     *     end_href: string,
     *     spine_start: int,
     *     spine_end: int,
     *     toc_level: int
     * } $planentry
     * @return array{
     *     title: string,
     *     subchapter: bool,
     *     importsrc: string,
     *     document: DOMDocument,
     *     assetmap: array<string, string>,
     *     sourceanchors: array<string, string>,
     *     fragmenttargets: array<string, string>,
     *     chapterid: int
     * }
     */
    private function prepare_payload(array $planentry): array {
        $document = $this->create_html_document();
        $wrapper = $this->get_wrapper($document);
        $assetmap = [];
        $stylemap = [];
        $sourceanchors = [];
        $fragmenttargets = [];
        $usedids = [];
        $importsrc = '';

        for ($index = (int)$planentry['spine_start']; $index < (int)$planentry['spine_end']; $index++) {
            if (!isset($this->spine[$index])) {
                continue;
            }

            $spineitem = $this->spine[$index];
            if (!$this->is_html_media_type((string)($spineitem['media_type'] ?? ''))) {
                continue;
            }

            $resolved = $this->resolve_package_file((string)($spineitem['href'] ?? ''));
            if ($resolved === null) {
                continue;
            }

            if ($importsrc === '') {
                $importsrc = '/' . $resolved['root_path'];
            }

            $xhtml = $this->load_xml_file($resolved['full_path'], 'chapter XHTML "' . $resolved['root_path'] . '"');
            $xpath = new DOMXPath($xhtml);
            $body = $this->find_first_element($xpath, '//*[local-name()="body"]');
            if ($body === null) {
                continue;
            }

            foreach ($this->collect_scoped_styles($xpath, $resolved['root_path'], $assetmap) as $css) {
                if ($css === '') {
                    continue;
                }

                $stylemap[md5($css)] = $css;
            }

            $section = $document->createElement('section');
            $section->setAttribute('class', 'lucimoo-section');
            $section->setAttribute('data-epub-path', $resolved['root_path']);

            $sectionid = $this->ensure_unique_id($usedids, $this->source_anchor_id($resolved['root_path']));
            $section->setAttribute('id', $sectionid);

            if (!isset($sourceanchors[$resolved['root_path']])) {
                $sourceanchors[$resolved['root_path']] = $sectionid;
            }

            $this->append_body_children($document, $section, $body);
            $this->remove_unwanted_nodes($section);
            $this->register_fragment_targets($section, $resolved['root_path'], $fragmenttargets, $usedids);
            $this->rewrite_embedded_assets($section, $resolved['root_path'], $assetmap);

            $wrapper->appendChild($section);
        }

        if ($wrapper->childNodes->length === 0) {
            throw new RuntimeException(
                'No XHTML body content was found for chapter "' . $this->chapter_title($planentry) . '".'
            );
        }

        if ($stylemap !== []) {
            $style = $document->createElement('style');
            $style->appendChild($document->createTextNode(implode("\n\n", array_values($stylemap))));
            $wrapper->insertBefore($style, $wrapper->firstChild);
        }

        if ($importsrc === '') {
            $importsrc = '/' . ltrim((string)($planentry['start_href'] ?? ''), '/');
        }

        return [
            'title' => $this->chapter_title($planentry),
            'subchapter' => !empty($planentry['subchapter']),
            'importsrc' => $importsrc,
            'document' => $document,
            'assetmap' => $assetmap,
            'sourceanchors' => $sourceanchors,
            'fragmenttargets' => $fragmenttargets,
            'chapterid' => 0,
        ];
    }

    /**
     * Splits a simple single-level TOC payload by heading tags as a compatibility fallback.
     *
     * @param array{
     *     title: string,
     *     subchapter: bool,
     *     importsrc: string,
     *     document: DOMDocument,
     *     assetmap: array<string, string>,
     *     sourceanchors: array<string, string>,
     *     fragmenttargets: array<string, string>,
     *     chapterid: int
     * } $payload
     * @return array<int, array{
     *     title: string,
     *     subchapter: bool,
     *     importsrc: string,
     *     document: DOMDocument,
     *     assetmap: array<string, string>,
     *     sourceanchors: array<string, string>,
     *     fragmenttargets: array<string, string>,
     *     chapterid: int
     * }>
     */
    private function split_payload_by_headings(array $payload): array {
        $headingtag = $this->detect_heading_split_tag($payload['document']);
        if ($headingtag === '') {
            return [$payload];
        }

        $wrapper = $this->get_wrapper($payload['document']);
        $working = new DOMDocument('1.0', 'UTF-8');
        $html = $working->appendChild($working->createElement('html'));
        $body = $html->appendChild($working->createElement('body'));
        $root = $body->appendChild($working->createElement('div'));
        $root->setAttribute('data-split-root', '1');

        $segmentsource = $working->createElement('div');
        $segmentsource->setAttribute('data-split-container', '1');
        $root->appendChild($segmentsource);

        $styletemplates = [];
        foreach ($wrapper->childNodes as $child) {
            if (!$child instanceof DOMNode) {
                continue;
            }

            if ($child instanceof DOMElement && strtolower($child->tagName) === 'style') {
                $styletemplates[] = $child->cloneNode(true);
                continue;
            }

            $segmentsource->appendChild($working->importNode($child, true));
        }

        $xpath = new DOMXPath($working);
        $headings = $xpath->query('//*[@data-split-container="1"]//*[local-name()="' . $headingtag . '"]');
        if ($headings === false || $headings->length === 0) {
            return [$payload];
        }

        $splitpoints = [];
        foreach ($headings as $heading) {
            if ($heading instanceof DOMElement) {
                $splitpoints[] = $heading;
            }
        }

        foreach ($splitpoints as $heading) {
            $this->split_container_before($segmentsource, $heading);
        }

        $segments = [];
        foreach ($root->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->getAttribute('data-split-container') !== '1') {
                continue;
            }

            $segments[] = $child;
        }

        if (count($segments) <= 1) {
            return [$payload];
        }

        $result = [];
        foreach ($segments as $index => $segment) {
            $title = $payload['title'];
            $subchapter = $index === 0 ? $payload['subchapter'] : true;

            if ($index > 0) {
                $headingnodes = (new DOMXPath($segment->ownerDocument))->query(
                    './/*[local-name()="' . $headingtag . '"][1]',
                    $segment
                );
                $heading = $headingnodes !== false && $headingnodes->length > 0 && $headingnodes->item(0) instanceof DOMElement
                    ? $headingnodes->item(0)
                    : null;
                if ($heading !== null) {
                    $title = $this->normalise_heading_text($heading->textContent);
                }
            }

            $splitpayload = $this->build_split_payload(
                $title,
                $subchapter,
                $payload['importsrc'],
                $payload['assetmap'],
                $payload['fragmenttargets'],
                $segment,
                $styletemplates
            );

            $result[] = $splitpayload;
        }

        return $result;
    }

    /**
     * Builds one split payload from a heading-derived content segment.
     *
     * @param string $title Chapter title.
     * @param bool $subchapter Whether the split segment is a subchapter.
     * @param string $importsrc Original import source path.
     * @param array<string, string> $assetmap Full asset map from the unsplit payload.
     * @param array<string, string> $fragmenttargets Original fragment target map.
     * @param DOMElement $segment Split content container.
     * @param array<int, DOMNode> $styletemplates Style nodes to prepend to every split chapter.
     * @return array{
     *     title: string,
     *     subchapter: bool,
     *     importsrc: string,
     *     document: DOMDocument,
     *     assetmap: array<string, string>,
     *     sourceanchors: array<string, string>,
     *     fragmenttargets: array<string, string>,
     *     chapterid: int
     * }
     */
    private function build_split_payload(
        string $title,
        bool $subchapter,
        string $importsrc,
        array $assetmap,
        array $fragmenttargets,
        DOMElement $segment,
        array $styletemplates
    ): array {
        $document = $this->create_html_document();
        $wrapper = $this->get_wrapper($document);

        foreach ($styletemplates as $style) {
            $wrapper->appendChild($document->importNode($style, true));
        }

        foreach ($segment->childNodes as $child) {
            $wrapper->appendChild($document->importNode($child, true));
        }

        $splitpayload = [
            'title' => $this->normalise_heading_text($title),
            'subchapter' => $subchapter,
            'importsrc' => $importsrc,
            'document' => $document,
            'assetmap' => $this->filter_assetmap_for_document($assetmap, $document),
            'sourceanchors' => [],
            'fragmenttargets' => [],
            'chapterid' => 0,
        ];

        return $this->rebuild_payload_metadata($splitpayload, $fragmenttargets);
    }

    /**
     * Rebuilds source and fragment metadata after payload splitting.
     *
     * @param array{
     *     title: string,
     *     subchapter: bool,
     *     importsrc: string,
     *     document: DOMDocument,
     *     assetmap: array<string, string>,
     *     sourceanchors: array<string, string>,
     *     fragmenttargets: array<string, string>,
     *     chapterid: int
     * } $payload
     * @param array<string, string> $originalfragments Original fragment target map.
     * @return array{
     *     title: string,
     *     subchapter: bool,
     *     importsrc: string,
     *     document: DOMDocument,
     *     assetmap: array<string, string>,
     *     sourceanchors: array<string, string>,
     *     fragmenttargets: array<string, string>,
     *     chapterid: int
     * }
     */
    private function rebuild_payload_metadata(array $payload, array $originalfragments): array {
        $xpath = new DOMXPath($payload['document']);
        $sourceanchors = [];
        $presentids = [];

        $sections = $xpath->query('//*[@data-epub-path]');
        if ($sections !== false) {
            foreach ($sections as $section) {
                if (!$section instanceof DOMElement) {
                    continue;
                }

                $path = trim($section->getAttribute('data-epub-path'));
                $id = trim($section->getAttribute('id'));
                if ($path !== '' && $id !== '' && !isset($sourceanchors[$path])) {
                    $sourceanchors[$path] = $id;
                }
            }
        }

        $idelements = $xpath->query('//*[@id]');
        if ($idelements !== false) {
            foreach ($idelements as $element) {
                if (!$element instanceof DOMElement) {
                    continue;
                }

                $id = trim($element->getAttribute('id'));
                if ($id !== '') {
                    $presentids[$id] = true;
                }
            }
        }

        $fragmenttargets = [];
        foreach ($originalfragments as $key => $id) {
            if (isset($presentids[$id])) {
                $fragmenttargets[$key] = $id;
            }
        }

        $payload['sourceanchors'] = $sourceanchors;
        $payload['fragmenttargets'] = $fragmenttargets;

        return $payload;
    }

    /**
     * Rewrites internal XHTML links after chapter ids are known.
     *
     * @param array<int, array{
     *     title: string,
     *     subchapter: bool,
     *     importsrc: string,
     *     document: DOMDocument,
     *     assetmap: array<string, string>,
     *     sourceanchors: array<string, string>,
     *     fragmenttargets: array<string, string>,
     *     chapterid: int
     * }> $payloads
     */
    private function rewrite_internal_links(array &$payloads): void {
        $chapterbysource = [];
        $anchorbysource = [];
        $chapterbyfragment = [];
        $anchorbyfragment = [];

        foreach ($payloads as $index => $payload) {
            foreach ($payload['sourceanchors'] as $sourcepath => $anchorid) {
                if (!isset($chapterbysource[$sourcepath])) {
                    $chapterbysource[$sourcepath] = $index;
                    $anchorbysource[$sourcepath] = $anchorid;
                }
            }

            foreach ($payload['fragmenttargets'] as $fragmentkey => $anchorid) {
                if (!isset($chapterbyfragment[$fragmentkey])) {
                    $chapterbyfragment[$fragmentkey] = $index;
                    $anchorbyfragment[$fragmentkey] = $anchorid;
                }
            }
        }

        foreach ($payloads as $index => &$payload) {
            $xpath = new DOMXPath($payload['document']);
            $anchors = $xpath->query('//a[@href]');
            if ($anchors === false) {
                continue;
            }

            foreach ($anchors as $anchor) {
                if (!$anchor instanceof DOMElement) {
                    continue;
                }

                $href = trim($anchor->getAttribute('href'));
                if ($href === '' || $this->has_uri_scheme($href)) {
                    continue;
                }

                [$targetpath, $fragment] = $this->split_fragment($href);
                $sourcepath = $this->source_path_for_element($anchor);
                if ($sourcepath === '') {
                    $sourcepath = $this->first_source_path($payload['sourceanchors']);
                }

                if ($sourcepath === '') {
                    continue;
                }

                if ($targetpath === '') {
                    $resolvedtarget = ['root_path' => $sourcepath];
                } else {
                    $resolvedtarget = $this->resolve_package_file($targetpath, $sourcepath);
                    if ($resolvedtarget === null || !$this->is_spine_document($resolvedtarget['root_path'])) {
                        continue;
                    }
                }

                $targetroot = $resolvedtarget['root_path'];
                $fragmentkey = $fragment === '' ? '' : $this->fragment_key($targetroot, $fragment);

                if ($fragmentkey !== '' && isset($chapterbyfragment[$fragmentkey])) {
                    $targetchapterindex = $chapterbyfragment[$fragmentkey];
                    $targetanchor = $anchorbyfragment[$fragmentkey];
                } else if (isset($chapterbysource[$targetroot])) {
                    $targetchapterindex = $chapterbysource[$targetroot];
                    $targetanchor = $anchorbysource[$targetroot];
                } else {
                    continue;
                }

                if ($targetchapterindex === $index) {
                    $anchor->setAttribute('href', '#' . $targetanchor);
                    continue;
                }

                $url = new \moodle_url(
                    '/mod/book/view.php',
                    [
                        'id' => $this->context->instanceid,
                        'chapterid' => $payloads[$targetchapterindex]['chapterid'],
                    ],
                    $targetanchor
                );

                $anchor->setAttribute('href', $url->out(false));
            }
        }
        unset($payload);
    }

    /**
     * Creates and inserts a placeholder Moodle Book chapter record.
     */
    private function create_placeholder_chapter(string $title, bool $subchapter, string $importsrc, int $pagenum): stdClass {
        global $DB;

        $timestamp = time();

        $chapter = new stdClass();
        $chapter->bookid = (int)$this->book->id;
        $chapter->pagenum = $pagenum;
        $chapter->subchapter = $subchapter ? 1 : 0;
        $chapter->importsrc = $importsrc;
        $chapter->title = $this->normalise_heading_text($title);
        $chapter->content = self::PLACEHOLDER_CONTENT;
        $chapter->contentformat = FORMAT_HTML;
        $chapter->hidden = 0;
        $chapter->timecreated = $timestamp;
        $chapter->timemodified = $timestamp;
        $chapter->id = $DB->insert_record('book_chapters', $chapter);

        return $chapter;
    }

    /**
     * Updates the final chapter HTML after sanitization and link rewriting.
     */
    private function update_chapter_content(int $chapterid, string $content): void {
        global $DB;

        $record = new stdClass();
        $record->id = $chapterid;
        $record->content = $content;
        $record->timemodified = time();

        $DB->update_record('book_chapters', $record);
    }

    /**
     * Stores media files into the Moodle chapter file area.
     *
     * @param stdClass $chapter Inserted chapter record.
     * @param array<string, string> $assetmap Map of filename => source path.
     */
    private function store_chapter_assets(stdClass $chapter, array $assetmap): void {
        $fs = get_file_storage();

        foreach ($assetmap as $filename => $sourcepath) {
            if (!is_file($sourcepath)) {
                throw new RuntimeException('Unable to store missing chapter asset: ' . $sourcepath);
            }

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

            $fs->create_file_from_pathname($filerecord, $sourcepath);
        }
    }

    /**
     * Appends imported body child nodes into a wrapper section.
     */
    private function append_body_children(DOMDocument $document, DOMElement $section, DOMElement $body): void {
        foreach ($body->childNodes as $child) {
            $section->appendChild($document->importNode($child, true));
        }
    }

    /**
     * Removes nodes that should not survive into Moodle chapter HTML.
     */
    private function remove_unwanted_nodes(DOMElement $section): void {
        $xpath = new DOMXPath($section->ownerDocument);
        $nodes = $xpath->query(
            './/*[local-name()="script" or local-name()="style" or local-name()="base" or local-name()="meta" or local-name()="title"]',
            $section
        );

        if ($nodes === false) {
            return;
        }

        $removals = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $removals[] = $node;
            }
        }

        foreach ($removals as $node) {
            if ($node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    /**
     * Collects inline and linked CSS, scopes it under `.lucimoo`, and rewrites url() assets.
     *
     * @param DOMXPath $xpath XHTML XPath helper.
     * @param string $xhtmlrootpath Root-relative path of the XHTML file.
     * @param array<string, string> $assetmap Map of filename => source path.
     * @return array<int, string>
     */
    private function collect_scoped_styles(DOMXPath $xpath, string $xhtmlrootpath, array &$assetmap): array {
        $styles = [];

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

                $scoped = $this->scope_css($css, $resolved['root_path'], $assetmap);
                if ($scoped !== '') {
                    $styles[] = $scoped;
                }
            }
        }

        $stylenodes = $xpath->query('//*[local-name()="style"]');
        if ($stylenodes !== false) {
            foreach ($stylenodes as $style) {
                if (!$style instanceof DOMElement) {
                    continue;
                }

                $css = trim($style->textContent ?? '');
                if ($css === '') {
                    continue;
                }

                $scoped = $this->scope_css($css, $xhtmlrootpath, $assetmap);
                if ($scoped !== '') {
                    $styles[] = $scoped;
                }
            }
        }

        return $styles;
    }

    /**
     * Scopes CSS selectors under `.lucimoo` and rewrites relative url() assets.
     *
     * @param string $css Raw CSS text.
     * @param string $basepath Root-relative path of the CSS source.
     * @param array<string, string> $assetmap Map of filename => source path.
     * @return string
     */
    private function scope_css(string $css, string $basepath, array &$assetmap): string {
        $css = preg_replace('/^\xEF\xBB\xBF/', '', $css) ?? $css;
        $css = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;
        $css = preg_replace('/@charset\s+[^;]+;/i', '', $css) ?? $css;
        $css = preg_replace('/@(?:import|namespace)\s+[^;]+;/i', '', $css) ?? $css;
        $css = $this->rewrite_css_urls($css, $basepath, $assetmap);
        $css = trim($css);

        if ($css === '') {
            return '';
        }

        return trim($this->scope_css_blocks($css));
    }

    /**
     * Recursively scopes CSS blocks under `.lucimoo`.
     */
    private function scope_css_blocks(string $css): string {
        $result = '';
        $offset = 0;
        $length = strlen($css);

        while ($offset < $length) {
            $openbrace = strpos($css, '{', $offset);
            if ($openbrace === false) {
                $tail = trim(substr($css, $offset));
                if ($tail !== '') {
                    $result .= $tail;
                }
                break;
            }

            $header = trim(substr($css, $offset, $openbrace - $offset));
            $closebrace = $this->find_matching_brace($css, $openbrace);
            if ($closebrace === null) {
                $remainder = trim(substr($css, $offset));
                if ($remainder !== '') {
                    $result .= $remainder;
                }
                break;
            }

            $body = substr($css, $openbrace + 1, $closebrace - $openbrace - 1);
            if ($header !== '') {
                if (str_starts_with(ltrim($header), '@')) {
                    if ($this->at_rule_contains_nested_selectors($header)) {
                        $body = $this->scope_css_blocks($body);
                    }

                    $result .= $header . '{' . $body . '}';
                } else {
                    $result .= $this->scope_selector_list($header) . '{' . $body . '}';
                }
            }

            $offset = $closebrace + 1;
        }

        return $result;
    }

    /**
     * Rewrites CSS url() references to Moodle pluginfile URLs.
     *
     * @param string $css Raw CSS text.
     * @param string $basepath Root-relative path of the CSS source.
     * @param array<string, string> $assetmap Map of filename => source path.
     * @return string
     */
    private function rewrite_css_urls(string $css, string $basepath, array &$assetmap): string {
        $rewritten = preg_replace_callback(
            '/url\(\s*(["\']?)([^)"\']+)\1\s*\)/i',
            function(array $matches) use ($basepath, &$assetmap): string {
                $url = trim($matches[2]);
                if (
                    $url === '' ||
                    $url[0] === '#' ||
                    str_starts_with($url, 'data:') ||
                    $this->has_uri_scheme($url)
                ) {
                    return $matches[0];
                }

                $resolved = $this->resolve_package_file($url, $basepath);
                if ($resolved === null) {
                    return $matches[0];
                }

                $filename = $this->register_asset($assetmap, $resolved['full_path'], basename($resolved['root_path']));

                return 'url(\'@@PLUGINFILE@@/' . $filename . '\')';
            },
            $css
        );

        return $rewritten ?? $css;
    }

    /**
     * Returns whether a CSS at-rule contains nested selectors that should be scoped recursively.
     */
    private function at_rule_contains_nested_selectors(string $header): bool {
        $header = ltrim($header);
        if (!str_starts_with($header, '@')) {
            return false;
        }

        if (preg_match('/^@([a-z-]+)/i', $header, $matches) !== 1) {
            return false;
        }

        return in_array(strtolower($matches[1]), ['media', 'supports', 'document', 'layer', 'scope'], true);
    }

    /**
     * Prefixes each selector in a comma-separated list with `.lucimoo`.
     */
    private function scope_selector_list(string $selectors): string {
        $parts = array_values(
            array_filter(
                array_map('trim', explode(',', $selectors)),
                static fn(string $selector): bool => $selector !== ''
            )
        );

        $parts = array_map([$this, 'scope_selector'], $parts);

        return implode(', ', $parts);
    }

    /**
     * Prefixes one selector with `.lucimoo`.
     */
    private function scope_selector(string $selector): string {
        $selector = trim($selector);
        if ($selector === '') {
            return '.lucimoo';
        }

        if (preg_match('/^(from|to|\d+%)$/i', $selector) === 1) {
            return $selector;
        }

        $selector = preg_replace(
            '/(^|[\s>+~,(])(?:html|body|:root)(?=($|[\s>+~.#[:]))/i',
            '$1.lucimoo',
            $selector
        ) ?? $selector;

        while (str_contains($selector, '.lucimoo .lucimoo')) {
            $selector = str_replace('.lucimoo .lucimoo', '.lucimoo', $selector);
        }

        if (preg_match('/(^|[\s>+~])\.lucimoo(?=($|[\s>+~.#[:]))/i', $selector) === 1) {
            return trim($selector);
        }

        return '.lucimoo ' . $selector;
    }

    /**
     * Rewrites embedded media and non-spine asset links to Moodle pluginfile URLs.
     *
     * @param DOMElement $root Root element to inspect.
     * @param string $sourcepath Root-relative path of the current XHTML file.
     * @param array<string, string> $assetmap Map of filename => source path.
     */
    private function rewrite_embedded_assets(DOMElement $root, string $sourcepath, array &$assetmap): void {
        $xpath = new DOMXPath($root->ownerDocument);
        $attributes = [
            ['xpath' => './/*[local-name()="img"][@src]', 'attribute' => 'src'],
            ['xpath' => './/*[local-name()="image"][@href]', 'attribute' => 'href'],
            ['xpath' => './/*[local-name()="audio"][@src]', 'attribute' => 'src'],
            ['xpath' => './/*[local-name()="video"][@src]', 'attribute' => 'src'],
            ['xpath' => './/*[local-name()="video"][@poster]', 'attribute' => 'poster'],
            ['xpath' => './/*[local-name()="source"][@src]', 'attribute' => 'src'],
            ['xpath' => './/*[local-name()="track"][@src]', 'attribute' => 'src'],
            ['xpath' => './/*[local-name()="object"][@data]', 'attribute' => 'data'],
            ['xpath' => './/*[local-name()="embed"][@src]', 'attribute' => 'src'],
        ];

        foreach ($attributes as $rule) {
            $nodes = $xpath->query($rule['xpath'], $root);
            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $this->rewrite_asset_attribute($node, $rule['attribute'], $sourcepath, $assetmap);
            }
        }

        $anchors = $xpath->query('.//a[@href]', $root);
        if ($anchors === false) {
            return;
        }

        foreach ($anchors as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }

            $href = trim($anchor->getAttribute('href'));
            if ($href === '' || $href[0] === '#' || $this->has_uri_scheme($href)) {
                continue;
            }

            $resolved = $this->resolve_package_file($href, $sourcepath);
            if ($resolved === null || $this->is_spine_document($resolved['root_path'])) {
                continue;
            }

            $filename = $this->register_asset($assetmap, $resolved['full_path'], basename($resolved['root_path']));
            $anchor->setAttribute('href', '@@PLUGINFILE@@/' . $filename);
        }
    }

    /**
     * Rewrites one media-bearing attribute to a Moodle pluginfile URL.
     *
     * @param DOMElement $element DOM element with a file-bearing attribute.
     * @param string $attribute Attribute name.
     * @param string $sourcepath Root-relative path of the current XHTML file.
     * @param array<string, string> $assetmap Map of filename => source path.
     */
    private function rewrite_asset_attribute(DOMElement $element, string $attribute, string $sourcepath, array &$assetmap): void {
        $value = trim($element->getAttribute($attribute));
        if ($value === '' || str_starts_with($value, '@@PLUGINFILE@@/') || $this->has_uri_scheme($value)) {
            return;
        }

        $resolved = $this->resolve_package_file($value, $sourcepath);
        if ($resolved === null) {
            return;
        }

        $filename = $this->register_asset($assetmap, $resolved['full_path'], basename($resolved['root_path']));
        $element->setAttribute($attribute, '@@PLUGINFILE@@/' . $filename);
    }

    /**
     * Registers fragment targets from ids and named anchors, rewriting them to unique ids.
     *
     * @param DOMElement $root Root section element.
     * @param string $sourcepath Root-relative path of the current XHTML file.
     * @param array<string, string> $fragmenttargets Map of sourcepath#fragment => unique id.
     * @param array<string, bool> $usedids Used ids within the current payload document.
     */
    private function register_fragment_targets(
        DOMElement $root,
        string $sourcepath,
        array &$fragmenttargets,
        array &$usedids
    ): void {
        foreach ($this->iterate_elements($root) as $element) {
            if ($element->isSameNode($root)) {
                continue;
            }

            $assignedid = '';

            if ($element->hasAttribute('id')) {
                $originalid = trim($element->getAttribute('id'));
                if ($originalid !== '') {
                    $assignedid = $this->ensure_unique_id($usedids, $this->fragment_anchor_id($sourcepath, $originalid));
                    $element->setAttribute('id', $assignedid);

                    $key = $this->fragment_key($sourcepath, $originalid);
                    if (!isset($fragmenttargets[$key])) {
                        $fragmenttargets[$key] = $assignedid;
                    }
                }
            }

            if (strtolower($element->tagName) !== 'a' || !$element->hasAttribute('name')) {
                continue;
            }

            $name = trim($element->getAttribute('name'));
            if ($name === '') {
                continue;
            }

            if ($assignedid === '') {
                $assignedid = $this->ensure_unique_id($usedids, $this->fragment_anchor_id($sourcepath, $name));
                $element->setAttribute('id', $assignedid);
            }

            $element->removeAttribute('name');
            $key = $this->fragment_key($sourcepath, $name);
            if (!isset($fragmenttargets[$key])) {
                $fragmenttargets[$key] = $assignedid;
            }
        }
    }

    /**
     * Returns the best heading tag to use for fallback splitting.
     */
    private function detect_heading_split_tag(DOMDocument $document): string {
        $xpath = new DOMXPath($document);
        $firstsingle = '';

        for ($level = 1; $level <= 6; $level++) {
            $tag = 'h' . $level;
            $nodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " lucimoo ")]//*[local-name()="' . $tag . '"]');
            $count = $nodes === false ? 0 : $nodes->length;

            if ($count >= 2) {
                return $tag;
            }

            if ($count === 1 && $firstsingle === '') {
                $firstsingle = $tag;
            }
        }

        return $firstsingle;
    }

    /**
     * Splits a container node before the given descendant element.
     */
    private function split_container_before(DOMElement $container, DOMElement $element): DOMElement {
        if ($element->isSameNode($container)) {
            return $container;
        }

        $parent = $element->parentNode;
        if (!$parent instanceof DOMElement || $parent->parentNode === null) {
            return $container;
        }

        if ($parent->firstChild !== null && !$parent->firstChild->isSameNode($element)) {
            if ($parent->firstChild->nodeType === XML_TEXT_NODE && trim($parent->firstChild->textContent ?? '') === '') {
                $parent->removeChild($parent->firstChild);
            }
        }

        if ($parent->firstChild !== null && !$parent->firstChild->isSameNode($element)) {
            $parentcopy = $parent->cloneNode(false);
            $parent->parentNode->insertBefore($parentcopy, $parent);

            while ($parent->firstChild !== null && !$parent->firstChild->isSameNode($element)) {
                $parentcopy->appendChild($parent->firstChild);
            }
        }

        return $this->split_container_before($container, $parent);
    }

    /**
     * Filters the asset map down to files actually referenced in a split payload.
     *
     * @param array<string, string> $assetmap Map of filename => source path.
     * @param DOMDocument $document Split payload document.
     * @return array<string, string>
     */
    private function filter_assetmap_for_document(array $assetmap, DOMDocument $document): array {
        $html = $this->render_payload_html($document);
        $filtered = [];

        foreach ($assetmap as $filename => $sourcepath) {
            if (str_contains($html, '@@PLUGINFILE@@/' . $filename)) {
                $filtered[$filename] = $sourcepath;
            }
        }

        return $filtered;
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
     * Builds a lookup of resolved spine XHTML files for internal-link detection.
     */
    private function build_spine_path_lookup(): void {
        if ($this->spinepaths !== []) {
            return;
        }

        foreach ($this->spine as $spineitem) {
            if (!$this->is_html_media_type((string)($spineitem['media_type'] ?? ''))) {
                continue;
            }

            $resolved = $this->resolve_package_file((string)($spineitem['href'] ?? ''));
            if ($resolved !== null) {
                $this->spinepaths[$resolved['root_path']] = true;
            }
        }
    }

    /**
     * Returns whether a media type is XHTML/HTML content.
     */
    private function is_html_media_type(string $mediatype): bool {
        $mediatype = strtolower(trim($mediatype));

        return $mediatype === 'application/xhtml+xml' || $mediatype === 'text/html';
    }

    /**
     * Returns whether the given root-relative path is one of the spine XHTML files.
     */
    private function is_spine_document(string $rootpath): bool {
        return isset($this->spinepaths[$rootpath]);
    }

    /**
     * Resolves an EPUB path to an extracted file on disk.
     *
     * @param string $path Path or href to resolve.
     * @param string|null $basepath Optional root-relative base document path.
     * @return array{root_path: string, full_path: string}|null
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
     * @param array<string, string> $assetmap Map of filename => source path.
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
     * Loads and parses an XML/XHTML file using the mandated security flags.
     */
    private function load_xml_file(string $filepath, string $label): DOMDocument {
        $xml = file_get_contents($filepath);
        if ($xml === false) {
            throw new RuntimeException('Unable to read ' . $label . '.');
        }

        $previouserrors = libxml_use_internal_errors(true);
        $previousloader = null;

        if (PHP_VERSION_ID < 80000) {
            $previousloader = libxml_disable_entity_loader(true);
        }

        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, self::XML_FLAGS);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previouserrors);

        if (PHP_VERSION_ID < 80000 && $previousloader !== null) {
            libxml_disable_entity_loader($previousloader);
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
     * Creates a minimal HTML document with a `.lucimoo` wrapper in the body.
     */
    private function create_html_document(): DOMDocument {
        $document = new DOMDocument('1.0', 'UTF-8');
        $html = $document->appendChild($document->createElement('html'));
        $body = $html->appendChild($document->createElement('body'));
        $wrapper = $body->appendChild($document->createElement('div'));
        $wrapper->setAttribute('class', 'lucimoo');

        return $document;
    }

    /**
     * Returns the `.lucimoo` wrapper from a payload document.
     */
    private function get_wrapper(DOMDocument $document): DOMElement {
        $xpath = new DOMXPath($document);
        $wrapper = $this->find_first_element(
            $xpath,
            '//div[contains(concat(" ", normalize-space(@class), " "), " lucimoo ")]'
        );

        if ($wrapper === null) {
            throw new RuntimeException('Chapter payload is missing its .lucimoo wrapper.');
        }

        return $wrapper;
    }

    /**
     * Renders the `.lucimoo` wrapper HTML for storage in Moodle.
     */
    private function render_payload_html(DOMDocument $document): string {
        $html = $document->saveHTML($this->get_wrapper($document));
        if ($html === false) {
            throw new RuntimeException('Unable to serialize chapter HTML.');
        }

        return $html;
    }

    /**
     * Returns the first matching element for an XPath query.
     */
    private function find_first_element(DOMXPath $xpath, string $expression): ?DOMElement {
        $nodes = $xpath->query($expression);
        if ($nodes === false || $nodes->length === 0 || !$nodes->item(0) instanceof DOMElement) {
            return null;
        }

        return $nodes->item(0);
    }

    /**
     * Returns a readable title for a chapter plan entry.
     *
     * @param array{
     *     title: string,
     *     subchapter: bool,
     *     start_href: string,
     *     end_href: string,
     *     spine_start: int,
     *     spine_end: int,
     *     toc_level: int
     * } $planentry
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

        return $this->normalise_heading_text($title);
    }

    /**
     * Normalises title and heading text for Moodle chapter titles.
     */
    private function normalise_heading_text(string $text): string {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            $text = 'Untitled Chapter';
        }

        return \core_text::substr($text, 0, 255);
    }

    /**
     * Returns whether heading fallback should be enabled for this import.
     */
    private function should_use_heading_fallback(): bool {
        if ($this->parser->get_layout() !== 'reflowable') {
            return false;
        }

        $toc = $this->parser->get_toc();
        if ($toc === []) {
            return false;
        }

        return $this->max_toc_level($toc) <= 1;
    }

    /**
     * Returns the maximum TOC depth.
     *
     * @param array<int, array{title: string, href: string, level: int, children: array}> $entries
     * @return int
     */
    private function max_toc_level(array $entries): int {
        $max = 0;

        foreach ($entries as $entry) {
            $level = (int)($entry['level'] ?? 0);
            $max = max($max, $level);

            $children = $entry['children'] ?? [];
            if (is_array($children) && $children !== []) {
                $max = max($max, $this->max_toc_level($children));
            }
        }

        return $max;
    }

    /**
     * Iterates over all descendant elements, including the root element.
     *
     * @return \Generator<int, DOMElement>
     */
    private function iterate_elements(DOMElement $root): \Generator {
        yield $root;

        foreach ($root->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            yield from $this->iterate_elements($child);
        }
    }

    /**
     * Returns the source XHTML path that contains the given element.
     */
    private function source_path_for_element(DOMElement $element): string {
        $node = $element;

        while ($node instanceof DOMElement) {
            $path = trim($node->getAttribute('data-epub-path'));
            if ($path !== '') {
                return $path;
            }

            $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null;
        }

        return '';
    }

    /**
     * Returns the first source path present in a payload.
     *
     * @param array<string, string> $sourceanchors Map of source path => anchor id.
     * @return string
     */
    private function first_source_path(array $sourceanchors): string {
        foreach ($sourceanchors as $path => $_anchor) {
            return $path;
        }

        return '';
    }

    /**
     * Builds a stable anchor id for a source-file section wrapper.
     */
    private function source_anchor_id(string $sourcepath): string {
        return 'lucimoo-src-' . substr(md5($sourcepath), 0, 12);
    }

    /**
     * Builds a stable anchor id for a fragment within one source file.
     */
    private function fragment_anchor_id(string $sourcepath, string $fragment): string {
        $fragment = preg_replace('/[^a-z0-9_-]+/i', '-', trim($fragment)) ?? '';
        $fragment = trim($fragment, '-_');
        if ($fragment === '') {
            $fragment = 'target';
        }

        return 'lucimoo-' . substr(md5($sourcepath), 0, 12) . '-' . \core_text::substr($fragment, 0, 48);
    }

    /**
     * Returns a canonical fragment lookup key.
     */
    private function fragment_key(string $sourcepath, string $fragment): string {
        return $sourcepath . '#' . trim($fragment);
    }

    /**
     * Ensures a generated HTML id is unique within one payload document.
     *
     * @param array<string, bool> $usedids Used ids map.
     * @param string $candidate Preferred id.
     * @return string
     */
    private function ensure_unique_id(array &$usedids, string $candidate): string {
        $candidate = trim($candidate);
        if ($candidate === '') {
            $candidate = 'lucimoo-target';
        }

        if (!isset($usedids[$candidate])) {
            $usedids[$candidate] = true;
            return $candidate;
        }

        $counter = 2;
        do {
            $id = $candidate . '-' . $counter;
            $counter++;
        } while (isset($usedids[$id]));

        $usedids[$id] = true;

        return $id;
    }

    /**
     * Finds the matching closing brace for a CSS block.
     */
    private function find_matching_brace(string $css, int $openbrace): ?int {
        $depth = 0;
        $length = strlen($css);
        $quote = '';

        for ($index = $openbrace; $index < $length; $index++) {
            $char = $css[$index];

            if ($quote !== '') {
                if ($char === '\\') {
                    $index++;
                    continue;
                }

                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === '\'') {
                $quote = $char;
                continue;
            }

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Splits a href into path and fragment components.
     *
     * @param string $href Href to split.
     * @return array{0: string, 1: string}
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
     */
    private function has_uri_scheme(string $href): bool {
        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $href) === 1;
    }
}
