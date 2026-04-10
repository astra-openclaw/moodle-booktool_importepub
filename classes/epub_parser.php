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
use DOMNode;
use DOMXPath;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use stdClass;
use stored_file;
use ZipArchive;

/**
 * Extracts and parses EPUB package metadata, spine, and table of contents.
 *
 * @package    booktool_epubimport
 * @copyright  2013-2018 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class epub_parser {
    /** @var int XML parsing flags mandated for this parser. */
    private const XML_FLAGS = LIBXML_NONET | LIBXML_NOENT;

    /** @var string EPUB mimetype marker. */
    private const EPUB_MIMETYPE = 'application/epub+zip';

    /** @var stored_file Source EPUB file from Moodle file storage. */
    private stored_file $file;

    /** @var string Destination directory for the extracted archive. */
    private string $tempdir;

    /** @var bool Whether the EPUB has been extracted and parsed. */
    private bool $extracted = false;

    /** @var string Relative path to the OPF package document from the EPUB root. */
    private string $opfpath = '';

    /** @var string Relative directory that contains the OPF file. */
    private string $opfdir = '';

    /** @var DOMDocument|null Parsed OPF document. */
    private ?DOMDocument $opfdocument = null;

    /** @var DOMXPath|null XPath helper for the OPF document. */
    private ?DOMXPath $opfxpath = null;

    /** @var array<string, array<string, string>> OPF manifest items keyed by id. */
    private array $manifest = [];

    /** @var string|null EPUB3 navigation document href relative to the OPF directory. */
    private ?string $navhref = null;

    /** @var string|null EPUB2 NCX document href relative to the OPF directory. */
    private ?string $ncxhref = null;

    /**
     * Constructor.
     *
     * @param stored_file $file EPUB file stored in Moodle file storage.
     * @param string $tempdir Temporary directory where the EPUB will be extracted.
     */
    public function __construct(stored_file $file, string $tempdir) {
        $tempdir = trim($tempdir);
        if ($tempdir === '') {
            throw new InvalidArgumentException('Temporary directory path cannot be empty.');
        }

        $this->file = $file;
        $this->tempdir = rtrim($tempdir, DIRECTORY_SEPARATOR);
    }

    /**
     * Extracts the EPUB archive, validates it, and loads the OPF package document.
     */
    public function extract(): void {
        if ($this->extracted) {
            return;
        }

        $this->prepare_tempdir();

        $archivepath = $this->tempdir . DIRECTORY_SEPARATOR . 'source.epub';
        if (!$this->file->copy_content_to($archivepath)) {
            throw new RuntimeException('Unable to copy EPUB content into the temporary directory.');
        }

        $zip = new ZipArchive();
        $openresult = $zip->open($archivepath);
        if ($openresult !== true) {
            @unlink($archivepath);
            throw new RuntimeException('Unable to open EPUB archive for extraction.');
        }

        $extracted = $zip->extractTo($this->tempdir);
        $zip->close();
        @unlink($archivepath);

        if (!$extracted) {
            throw new RuntimeException('Unable to extract EPUB archive into the temporary directory.');
        }

        $this->validate_mimetype();
        $this->opfpath = $this->locate_opf_path();
        $this->opfdir = $this->directory_name($this->opfpath);

        // Mark as extracted before loading OPF, since load_opf_document()
        // calls methods that require the extracted flag.
        $this->extracted = true;

        $this->load_opf_document();
    }

    /**
     * Returns the book layout mode.
     *
     * @return string Either 'fixed' or 'reflowable'.
     */
    public function get_layout(): string {
        $this->require_extracted();

        $layout = strtolower($this->read_xpath_text(
            '/opf:package/opf:metadata/opf:meta[@property="rendition:layout"][1]'
        ));

        return $layout === 'pre-paginated' ? 'fixed' : 'reflowable';
    }

    /**
     * Returns package metadata from the OPF document.
     *
     * @return stdClass Object with title, creator, language, identifier, publisher, and date.
     */
    public function get_metadata(): object {
        $this->require_extracted();

        $metadata = new stdClass();
        $metadata->title = $this->read_xpath_text('/opf:package/opf:metadata/dc:title[1]');
        $metadata->creator = $this->read_xpath_text('/opf:package/opf:metadata/dc:creator[1]');
        $metadata->language = $this->read_xpath_text('/opf:package/opf:metadata/dc:language[1]');
        $metadata->identifier = $this->read_xpath_text('/opf:package/opf:metadata/dc:identifier[1]');
        $metadata->publisher = $this->read_xpath_text('/opf:package/opf:metadata/dc:publisher[1]');
        $metadata->date = $this->read_xpath_text('/opf:package/opf:metadata/dc:date[1]');

        if ($metadata->date === '') {
            $metadata->date = $this->read_xpath_text(
                '/opf:package/opf:metadata/opf:meta[@property="dcterms:modified"][1]'
            );
        }

        return $metadata;
    }

    /**
     * Returns the ordered OPF spine.
     *
     * @return array<int, array<string, string>> Ordered OPF spine items.
     */
    public function get_spine(): array {
        $this->require_extracted();

        $spine = [];
        $itemrefs = $this->query_xpath('/opf:package/opf:spine/opf:itemref');

        foreach ($itemrefs as $itemref) {
            if (!$itemref instanceof DOMElement) {
                continue;
            }

            $idref = trim($itemref->getAttribute('idref'));
            if ($idref === '') {
                continue;
            }

            if (!isset($this->manifest[$idref])) {
                throw new RuntimeException('Spine item references a missing manifest entry: ' . $idref);
            }

            $manifestitem = $this->manifest[$idref];
            $spine[] = [
                'id' => $idref,
                'href' => $manifestitem['href'],
                'media_type' => $manifestitem['media_type'],
            ];
        }

        return $spine;
    }

    /**
     * Returns the hierarchical table of contents.
     *
     * @return array<int, array<string, mixed>> Hierarchical TOC entries.
     */
    public function get_toc(): array {
        $this->require_extracted();

        if ($this->navhref !== null) {
            $entries = $this->parse_nav_document($this->navhref);
            if ($entries !== []) {
                return $entries;
            }
        }

        if ($this->ncxhref !== null) {
            return $this->parse_ncx_document($this->ncxhref);
        }

        return [];
    }

    /**
     * Returns the extraction directory.
     */
    public function get_tempdir(): string {
        return $this->tempdir;
    }

    /**
     * Removes the extraction directory and resets parser state.
     */
    public function cleanup(): void {
        if (is_file($this->tempdir)) {
            @unlink($this->tempdir);
        } else if (is_dir($this->tempdir)) {
            $this->delete_directory($this->tempdir);
        }

        $this->reset_state();
    }

    /**
     * Ensures the extraction directory exists and is empty.
     */
    private function prepare_tempdir(): void {
        global $CFG;

        if (file_exists($this->tempdir) && !is_dir($this->tempdir)) {
            throw new RuntimeException('Temporary extraction path exists and is not a directory.');
        }

        $permissions = $CFG->directorypermissions ?? 02777;
        if (is_string($permissions)) {
            $permissions = octdec($permissions);
        }

        if (!is_dir($this->tempdir) && !mkdir($this->tempdir, $permissions, true) && !is_dir($this->tempdir)) {
            throw new RuntimeException('Unable to create temporary extraction directory.');
        }

        $items = scandir($this->tempdir);
        if ($items === false) {
            throw new RuntimeException('Unable to inspect temporary extraction directory.');
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->delete_directory($this->tempdir . DIRECTORY_SEPARATOR . $item);
        }
    }

    /**
     * Validates the extracted EPUB mimetype file.
     */
    private function validate_mimetype(): void {
        $mimetypepath = $this->tempdir . DIRECTORY_SEPARATOR . 'mimetype';
        if (!is_file($mimetypepath)) {
            // Many real-world EPUBs (especially repacked or DRM-free exports) omit the
            // mimetype file. Fall back to validating that container.xml exists instead,
            // which is the true structural requirement.
            $containerpath = $this->tempdir . DIRECTORY_SEPARATOR . 'META-INF'
                . DIRECTORY_SEPARATOR . 'container.xml';
            if (!is_file($containerpath)) {
                throw new RuntimeException(
                    'Invalid EPUB: missing both mimetype file and META-INF/container.xml.'
                );
            }
            return;
        }

        $mimetype = file_get_contents($mimetypepath);
        if ($mimetype === false || trim($mimetype) !== self::EPUB_MIMETYPE) {
            throw new RuntimeException('Invalid EPUB: unexpected mimetype declaration.');
        }
    }

    /**
     * Reads container.xml and returns the OPF package path.
     *
     * @return string Relative path from the EPUB root to the OPF file.
     */
    private function locate_opf_path(): string {
        $containerpath = $this->tempdir . DIRECTORY_SEPARATOR . 'META-INF' . DIRECTORY_SEPARATOR . 'container.xml';
        if (!is_file($containerpath)) {
            throw new RuntimeException('Invalid EPUB: missing META-INF/container.xml.');
        }

        $document = $this->load_xml_file($containerpath, 'EPUB container');
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('container', 'urn:oasis:names:tc:opendocument:xmlns:container');

        $nodes = $xpath->query('/container:container/container:rootfiles/container:rootfile[@full-path]');
        if ($nodes === false || $nodes->length === 0) {
            throw new RuntimeException('Invalid EPUB: container.xml does not declare an OPF package.');
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $fullpath = $this->normalise_path(trim($node->getAttribute('full-path')));
            if ($fullpath === '') {
                continue;
            }

            $resolved = $this->tempdir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $fullpath);
            if (is_file($resolved)) {
                return $fullpath;
            }
        }

        throw new RuntimeException('Invalid EPUB: declared OPF package file was not found.');
    }

    /**
     * Loads and indexes the OPF package document.
     */
    private function load_opf_document(): void {
        $opfdocument = $this->load_xml_file($this->full_path_from_root($this->opfpath), 'OPF package');
        $opfxpath = new DOMXPath($opfdocument);
        $opfxpath->registerNamespace('opf', 'http://www.idpf.org/2007/opf');
        $opfxpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');

        $this->opfdocument = $opfdocument;
        $this->opfxpath = $opfxpath;
        $this->manifest = [];
        $this->navhref = null;
        $this->ncxhref = null;

        $tocid = $this->read_xpath_attribute('/opf:package/opf:spine[1]', 'toc');
        $manifestitems = $this->query_xpath('/opf:package/opf:manifest/opf:item');

        foreach ($manifestitems as $item) {
            if (!$item instanceof DOMElement) {
                continue;
            }

            $id = trim($item->getAttribute('id'));
            if ($id === '') {
                continue;
            }

            $href = $this->normalise_path(trim($item->getAttribute('href')));
            $mediatype = trim($item->getAttribute('media-type'));
            $properties = trim($item->getAttribute('properties'));

            $this->manifest[$id] = [
                'href' => $href,
                'media_type' => $mediatype,
                'properties' => $properties,
            ];

            if ($this->has_token($properties, 'nav')) {
                $this->navhref = $href;
            }

            if ($mediatype === 'application/x-dtbncx+xml' || ($tocid !== '' && $tocid === $id)) {
                $this->ncxhref = $href;
            }
        }
    }

    /**
     * Parses an EPUB3 navigation document.
     *
     * @param string $navhref Navigation document href relative to the OPF directory.
     * @return array<int, array<string, mixed>> Hierarchical TOC entries.
     */
    private function parse_nav_document(string $navhref): array {
        $filepath = $this->full_path_from_opf($navhref);
        if (!is_file($filepath)) {
            return [];
        }

        $document = $this->load_xml_file($filepath, 'EPUB navigation document');
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');
        $xpath->registerNamespace('epub', 'http://www.idpf.org/2007/ops');

        $navnodes = $xpath->query(
            '//xhtml:nav[contains(concat(" ", normalize-space(@epub:type), " "), " toc ")' .
            ' or contains(concat(" ", normalize-space(@role), " "), " doc-toc ")]'
        );

        if ($navnodes === false || $navnodes->length === 0 || !$navnodes->item(0) instanceof DOMElement) {
            return [];
        }

        $toplist = $xpath->query('./xhtml:ol[1]', $navnodes->item(0));
        if ($toplist === false || $toplist->length === 0 || !$toplist->item(0) instanceof DOMElement) {
            return [];
        }

        return $this->parse_nav_list($xpath, $toplist->item(0), 1, $navhref);
    }

    /**
     * Parses an EPUB2 NCX table of contents document.
     *
     * @param string $ncxhref NCX document href relative to the OPF directory.
     * @return array<int, array<string, mixed>> Hierarchical TOC entries.
     */
    private function parse_ncx_document(string $ncxhref): array {
        $filepath = $this->full_path_from_opf($ncxhref);
        if (!is_file($filepath)) {
            return [];
        }

        $document = $this->load_xml_file($filepath, 'EPUB NCX document');
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ncx', 'http://www.daisy.org/z3986/2005/ncx/');

        $navmap = $xpath->query('/ncx:ncx/ncx:navMap[1]');
        if ($navmap === false || $navmap->length === 0 || !$navmap->item(0) instanceof DOMElement) {
            return [];
        }

        return $this->parse_ncx_points($xpath, $navmap->item(0), 1, $ncxhref);
    }

    /**
     * Recursively parses an EPUB3 ordered list into TOC entries.
     *
     * @param DOMXPath $xpath Navigation document XPath helper.
     * @param DOMElement $list List element to parse.
     * @param int $level Current TOC depth, starting at 1.
     * @param string $basehref Source file href relative to the OPF directory.
     * @return array<int, array<string, mixed>> Hierarchical TOC entries.
     */
    private function parse_nav_list(DOMXPath $xpath, DOMElement $list, int $level, string $basehref): array {
        $entries = [];
        $items = $xpath->query('./xhtml:li', $list);

        if ($items === false) {
            return $entries;
        }

        foreach ($items as $item) {
            if (!$item instanceof DOMElement) {
                continue;
            }

            $linknodes = $xpath->query('./xhtml:a[1]', $item);
            $labelnodes = $linknodes !== false && $linknodes->length > 0
                ? $linknodes
                : $xpath->query('./xhtml:span[1]', $item);

            $label = '';
            $href = '';

            if ($labelnodes !== false && $labelnodes->length > 0 && $labelnodes->item(0) instanceof DOMElement) {
                $label = trim($labelnodes->item(0)->textContent);
                if ($labelnodes->item(0)->hasAttribute('href')) {
                    $href = $this->resolve_href($basehref, trim($labelnodes->item(0)->getAttribute('href')));
                }
            }

            if ($label === '') {
                $label = trim($item->textContent);
            }

            $children = [];
            $childlists = $xpath->query('./xhtml:ol[1]', $item);
            if ($childlists !== false && $childlists->length > 0 && $childlists->item(0) instanceof DOMElement) {
                $children = $this->parse_nav_list($xpath, $childlists->item(0), $level + 1, $basehref);
            }

            $entries[] = [
                'title' => $label,
                'href' => $href,
                'level' => $level,
                'children' => $children,
            ];
        }

        return $entries;
    }

    /**
     * Recursively parses NCX navPoint elements into TOC entries.
     *
     * @param DOMXPath $xpath NCX XPath helper.
     * @param DOMElement $parent Current navMap or navPoint element.
     * @param int $level Current TOC depth, starting at 1.
     * @param string $basehref Source NCX href relative to the OPF directory.
     * @return array<int, array<string, mixed>> Hierarchical TOC entries.
     */
    private function parse_ncx_points(DOMXPath $xpath, DOMElement $parent, int $level, string $basehref): array {
        $entries = [];
        $points = $xpath->query('./ncx:navPoint', $parent);

        if ($points === false) {
            return $entries;
        }

        foreach ($points as $point) {
            if (!$point instanceof DOMElement) {
                continue;
            }

            $title = trim($this->read_relative_xpath_text($xpath, './ncx:navLabel/ncx:text[1]', $point));
            $src = trim($this->read_relative_xpath_attribute($xpath, './ncx:content[1]', 'src', $point));
            $children = $this->parse_ncx_points($xpath, $point, $level + 1, $basehref);

            $entries[] = [
                'title' => $title,
                'href' => $src === '' ? '' : $this->resolve_href($basehref, $src),
                'level' => $level,
                'children' => $children,
            ];
        }

        return $entries;
    }

    /**
     * Loads and parses an XML file using the mandated security flags.
     *
     * @param string $filepath Absolute path to the XML file.
     * @param string $label Context label for exception messages.
     * @return DOMDocument
     */
    private function load_xml_file(string $filepath, string $label): DOMDocument {
        $xml = file_get_contents($filepath);
        if ($xml === false) {
            throw new RuntimeException('Unable to read ' . $label . '.');
        }

        return $this->load_xml_string($xml, $label);
    }

    /**
     * Loads and parses XML text using the mandated security flags.
     *
     * @param string $xml Raw XML text.
     * @param string $label Context label for exception messages.
     * @return DOMDocument
     */
    private function load_xml_string(string $xml, string $label): DOMDocument {
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
     * Returns trimmed text for an OPF XPath query.
     *
     * @param string $expression XPath expression.
     * @return string
     */
    private function read_xpath_text(string $expression): string {
        $nodes = $this->query_xpath($expression);
        if ($nodes->length === 0) {
            return '';
        }

        return trim($nodes->item(0)?->textContent ?? '');
    }

    /**
     * Returns an attribute value for an OPF XPath query.
     *
     * @param string $expression XPath expression.
     * @param string $attribute Attribute name.
     * @return string
     */
    private function read_xpath_attribute(string $expression, string $attribute): string {
        $nodes = $this->query_xpath($expression);
        if ($nodes->length === 0 || !$nodes->item(0) instanceof DOMElement) {
            return '';
        }

        return trim($nodes->item(0)->getAttribute($attribute));
    }

    /**
     * Returns trimmed text for an XPath query relative to a specific node.
     *
     * @param DOMXPath $xpath XPath helper.
     * @param string $expression Relative XPath expression.
     * @param DOMNode $context Context node.
     * @return string
     */
    private function read_relative_xpath_text(DOMXPath $xpath, string $expression, DOMNode $context): string {
        $nodes = $xpath->query($expression, $context);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return trim($nodes->item(0)?->textContent ?? '');
    }

    /**
     * Returns an attribute value for an XPath query relative to a specific node.
     *
     * @param DOMXPath $xpath XPath helper.
     * @param string $expression Relative XPath expression.
     * @param string $attribute Attribute name.
     * @param DOMNode $context Context node.
     * @return string
     */
    private function read_relative_xpath_attribute(
        DOMXPath $xpath,
        string $expression,
        string $attribute,
        DOMNode $context
    ): string {
        $nodes = $xpath->query($expression, $context);
        if ($nodes === false || $nodes->length === 0 || !$nodes->item(0) instanceof DOMElement) {
            return '';
        }

        return trim($nodes->item(0)->getAttribute($attribute));
    }

    /**
     * Executes an XPath query against the loaded OPF document.
     *
     * @param string $expression XPath expression.
     * @return \DOMNodeList
     */
    private function query_xpath(string $expression): \DOMNodeList {
        $this->require_extracted();

        $nodes = $this->opfxpath?->query($expression);
        if ($nodes === false) {
            throw new RuntimeException('Invalid OPF XPath query: ' . $expression);
        }

        return $nodes;
    }

    /**
     * Ensures the OPF has been extracted and parsed.
     */
    private function require_extracted(): void {
        if (!$this->extracted || $this->opfdocument === null || $this->opfxpath === null) {
            throw new LogicException('Call extract() before reading EPUB data.');
        }
    }

    /**
     * Resolves an href against the directory of the source document.
     *
     * @param string $basehref Source document href relative to the OPF directory.
     * @param string $href Link href to resolve.
     * @return string Resolved href relative to the OPF directory.
     */
    private function resolve_href(string $basehref, string $href): string {
        if ($href === '') {
            return '';
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $href) === 1) {
            return $href;
        }

        [$path, $fragment] = $this->split_fragment($href);

        if ($path === '') {
            $resolved = $basehref;
        } else {
            $basedir = $this->directory_name($basehref);
            $resolved = $basedir === '' ? $path : $basedir . '/' . $path;
        }

        $resolved = $this->normalise_path($resolved);

        if ($fragment !== '') {
            $resolved .= '#' . $fragment;
        }

        return $resolved;
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
     * Resolves a package-relative path to an absolute filesystem path.
     *
     * @param string $path Relative path from the EPUB root.
     * @return string
     */
    private function full_path_from_root(string $path): string {
        $path = $this->normalise_path($path);

        return $this->tempdir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Resolves an OPF-relative path to an absolute filesystem path.
     *
     * @param string $path Relative path from the OPF directory.
     * @return string
     */
    private function full_path_from_opf(string $path): string {
        $path = $this->normalise_path($path);
        $packagepath = $this->opfdir === '' ? $path : $this->opfdir . '/' . $path;

        return $this->full_path_from_root($packagepath);
    }

    /**
     * Determines whether a space-separated attribute contains a given token.
     *
     * @param string $value Attribute value.
     * @param string $token Token to check.
     * @return bool
     */
    private function has_token(string $value, string $token): bool {
        $parts = preg_split('/\s+/', trim($value)) ?: [];

        return in_array($token, $parts, true);
    }

    /**
     * Deletes a file or directory tree.
     *
     * @param string $path Absolute filesystem path.
     */
    private function delete_directory(string $path): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->delete_directory($path . DIRECTORY_SEPARATOR . $item);
        }

        @rmdir($path);
    }

    /**
     * Resets parser state after cleanup.
     */
    private function reset_state(): void {
        $this->extracted = false;
        $this->opfpath = '';
        $this->opfdir = '';
        $this->opfdocument = null;
        $this->opfxpath = null;
        $this->manifest = [];
        $this->navhref = null;
        $this->ncxhref = null;
    }
}
