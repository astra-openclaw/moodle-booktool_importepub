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

use booktool_epubimport\epub_parser;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * PHPUnit coverage for the EPUB parser.
 *
 * @package    booktool_epubimport
 * @copyright  2013-2018 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(epub_parser::class)]
final class epub_parser_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_extract_valid_epub(): void {
        $parser = $this->create_parser_from_fixture('test-reflowable.epub');

        $parser->extract();

        $tempdir = $parser->get_tempdir();
        $this->assertTrue(is_dir($tempdir));
        $this->assertTrue(is_file($tempdir . '/mimetype'));
        $this->assertTrue(is_file($tempdir . '/OEBPS/package.opf'));
    }

    public function test_extract_invalid_file(): void {
        $invalidpath = $this->create_temp_file('not-an-epub.txt', 'This is not a ZIP archive.');
        $parser = $this->create_parser_from_path($invalidpath);

        $this->expectException(RuntimeException::class);
        $parser->extract();
    }

    public function test_get_layout_fixed(): void {
        $parser = $this->create_parser_from_fixture('test-fixed-layout.epub');
        $parser->extract();

        $this->assertSame('fixed', $parser->get_layout());
    }

    public function test_get_layout_reflowable(): void {
        $parser = $this->create_parser_from_fixture('test-reflowable.epub');
        $parser->extract();

        $this->assertSame('reflowable', $parser->get_layout());
    }

    public function test_get_metadata(): void {
        $parser = $this->create_parser_from_fixture('test-reflowable.epub');
        $parser->extract();

        $metadata = $parser->get_metadata();

        $this->assertSame('Reflowable Test Book', $metadata->title);
        $this->assertSame('Test Author', $metadata->creator);
    }

    public function test_get_spine(): void {
        $parser = $this->create_parser_from_fixture('test-reflowable.epub');
        $parser->extract();

        $this->assertSame([
            [
                'id' => 'chapter1',
                'href' => 'text/chapter1.xhtml',
                'media_type' => 'application/xhtml+xml',
            ],
            [
                'id' => 'chapter2',
                'href' => 'text/chapter2.xhtml',
                'media_type' => 'application/xhtml+xml',
            ],
            [
                'id' => 'chapter3',
                'href' => 'text/chapter3.xhtml',
                'media_type' => 'application/xhtml+xml',
            ],
        ], $parser->get_spine());
    }

    public function test_get_toc_epub3(): void {
        $parser = $this->create_parser_from_fixture('test-reflowable.epub');
        $parser->extract();

        $this->assertSame([
            [
                'title' => 'Chapter 1',
                'href' => 'text/chapter1.xhtml',
                'level' => 1,
                'children' => [],
            ],
            [
                'title' => 'Chapter 2',
                'href' => 'text/chapter2.xhtml',
                'level' => 1,
                'children' => [],
            ],
            [
                'title' => 'Chapter 3',
                'href' => 'text/chapter3.xhtml',
                'level' => 1,
                'children' => [],
            ],
        ], $parser->get_toc());
    }

    public function test_get_toc_epub2(): void {
        $epubpath = $this->create_epub2_fixture();
        $parser = $this->create_parser_from_path($epubpath);
        $parser->extract();

        $this->assertSame([
            [
                'title' => 'Chapter 1',
                'href' => 'text/chapter1.xhtml',
                'level' => 1,
                'children' => [],
            ],
            [
                'title' => 'Chapter 2',
                'href' => 'text/chapter2.xhtml',
                'level' => 1,
                'children' => [
                    [
                        'title' => 'Chapter 2.1',
                        'href' => 'text/chapter2.xhtml#section-1',
                        'level' => 2,
                        'children' => [],
                    ],
                ],
            ],
        ], $parser->get_toc());
    }

    public function test_xml_security(): void {
        $marker = 'xxe-marker-' . uniqid('', true);
        $secretpath = $this->create_temp_file('secret.txt', $marker);
        $epubpath = $this->create_xxe_epub($secretpath);
        $parser = $this->create_parser_from_path($epubpath);

        try {
            $parser->extract();
            $metadata = $parser->get_metadata();
            $combined = $metadata->title . ' ' . $metadata->creator;

            $this->assertStringNotContainsString($marker, $combined);
            $this->assertNotSame($marker, $metadata->title);
        } catch (RuntimeException $exception) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_cleanup(): void {
        $parser = $this->create_parser_from_fixture('test-reflowable.epub');
        $parser->extract();
        $tempdir = $parser->get_tempdir();

        $parser->cleanup();

        $this->assertFalse(file_exists($tempdir));
    }

    /**
     * Creates a parser using a bundled fixture EPUB.
     *
     * @param string $filename Fixture filename.
     * @return epub_parser
     */
    private function create_parser_from_fixture(string $filename): epub_parser {
        return $this->create_parser_from_path($this->fixture_path($filename));
    }

    /**
     * Creates a parser using a file path stored in Moodle file storage.
     *
     * @param string $pathname Source path to import.
     * @return epub_parser
     */
    private function create_parser_from_path(string $pathname): epub_parser {
        $storedfile = $this->create_stored_file($pathname);
        $tempdir = $this->make_extraction_dir();

        return new epub_parser($storedfile, $tempdir);
    }

    /**
     * Stores a test file in Moodle's file API.
     *
     * @param string $pathname Source path to persist.
     * @return stored_file
     */
    private function create_stored_file(string $pathname): stored_file {
        $fs = get_file_storage();
        $record = [
            'contextid' => \context_system::instance()->id,
            'component' => 'booktool_epubimport',
            'filearea' => 'tests',
            'itemid' => random_int(1, 1000000),
            'filepath' => '/',
            'filename' => basename($pathname),
        ];

        return $fs->create_file_from_pathname($record, $pathname);
    }

    /**
     * Creates a unique extraction directory path for parser tests.
     *
     * @return string
     */
    private function make_extraction_dir(): string {
        return make_request_directory() . DIRECTORY_SEPARATOR . 'epub-parser-' . uniqid('', true);
    }

    /**
     * Writes a temporary file into the request directory.
     *
     * @param string $filename Basename for the generated file.
     * @param string $contents File contents.
     * @return string
     */
    private function create_temp_file(string $filename, string $contents): string {
        $directory = make_request_directory() . DIRECTORY_SEPARATOR . 'fixtures-' . uniqid('', true);
        mkdir($directory, 0700, true);

        $pathname = $directory . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($pathname, $contents);

        return $pathname;
    }

    /**
     * Builds an EPUB2 fixture archive for legacy TOC coverage.
     *
     * @return string
     */
    private function create_epub2_fixture(): string {
        $files = [
            'mimetype' => 'application/epub+zip',
            'META-INF/container.xml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>
XML
,
            'OEBPS/content.opf' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<package version="2.0" xmlns="http://www.idpf.org/2007/opf" unique-identifier="bookid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:title>EPUB2 Test Book</dc:title>
    <dc:creator>Test Author</dc:creator>
    <dc:language>en</dc:language>
    <dc:identifier id="bookid">urn:uuid:epub2-test</dc:identifier>
  </metadata>
  <manifest>
    <item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
    <item id="chapter1" href="text/chapter1.xhtml" media-type="application/xhtml+xml"/>
    <item id="chapter2" href="text/chapter2.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine toc="ncx">
    <itemref idref="chapter1"/>
    <itemref idref="chapter2"/>
  </spine>
</package>
XML
,
            'OEBPS/toc.ncx' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">
  <head>
    <meta name="dtb:uid" content="urn:uuid:epub2-test"/>
  </head>
  <docTitle>
    <text>EPUB2 Test Book</text>
  </docTitle>
  <navMap>
    <navPoint id="nav-1" playOrder="1">
      <navLabel><text>Chapter 1</text></navLabel>
      <content src="text/chapter1.xhtml"/>
    </navPoint>
    <navPoint id="nav-2" playOrder="2">
      <navLabel><text>Chapter 2</text></navLabel>
      <content src="text/chapter2.xhtml"/>
      <navPoint id="nav-2-1" playOrder="3">
        <navLabel><text>Chapter 2.1</text></navLabel>
        <content src="text/chapter2.xhtml#section-1"/>
      </navPoint>
    </navPoint>
  </navMap>
</ncx>
XML
,
            'OEBPS/text/chapter1.xhtml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml">
  <head><title>Chapter 1</title></head>
  <body><h1>Chapter 1</h1></body>
</html>
XML
,
            'OEBPS/text/chapter2.xhtml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml">
  <head><title>Chapter 2</title></head>
  <body><h1>Chapter 2</h1><h2 id="section-1">Section 1</h2></body>
</html>
XML
,
        ];

        return $this->create_epub_archive('test-epub2.epub', $files);
    }

    /**
     * Builds an EPUB fixture that attempts XXE expansion.
     *
     * @param string $secretpath Path to the secret file used in the XXE payload.
     * @return string
     */
    private function create_xxe_epub(string $secretpath): string {
        $secreturi = 'file://' . str_replace(DIRECTORY_SEPARATOR, '/', $secretpath);
        $files = [
            'mimetype' => 'application/epub+zip',
            'META-INF/container.xml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="OEBPS/package.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>
XML
,
            'OEBPS/package.opf' => <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE package [
  <!ENTITY xxe SYSTEM "{$secreturi}">
]>
<package version="3.0" xmlns="http://www.idpf.org/2007/opf" unique-identifier="bookid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:title>&xxe;</dc:title>
    <dc:creator>Safe Author</dc:creator>
    <dc:language>en</dc:language>
    <dc:identifier id="bookid">urn:uuid:xxe-test</dc:identifier>
  </metadata>
  <manifest>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="chapter1" href="text/chapter1.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine>
    <itemref idref="chapter1"/>
  </spine>
</package>
XML
,
            'OEBPS/nav.xhtml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
  <head><title>TOC</title></head>
  <body>
    <nav epub:type="toc">
      <ol>
        <li><a href="text/chapter1.xhtml">Chapter 1</a></li>
      </ol>
    </nav>
  </body>
</html>
XML
,
            'OEBPS/text/chapter1.xhtml' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml">
  <head><title>Chapter 1</title></head>
  <body><h1>Chapter 1</h1></body>
</html>
XML
,
        ];

        return $this->create_epub_archive('test-xxe.epub', $files);
    }

    /**
     * Creates a ZIP archive from a map of EPUB file contents.
     *
     * @param string $filename Output EPUB filename.
     * @param array<string, string> $files Archive members keyed by path.
     * @return string
     */
    private function create_epub_archive(string $filename, array $files): string {
        $directory = make_request_directory() . DIRECTORY_SEPARATOR . 'generated-epubs-' . uniqid('', true);
        mkdir($directory, 0700, true);

        $pathname = $directory . DIRECTORY_SEPARATOR . $filename;
        $zip = new ZipArchive();
        $result = $zip->open($pathname, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($result === true, 'Unable to create test EPUB archive.');

        foreach ($files as $path => $contents) {
            $this->assertTrue($zip->addFromString($path, $contents), 'Unable to add ' . $path . ' to test EPUB.');
        }

        $zip->close();

        return $pathname;
    }

    /**
     * Returns the full path to a bundled test fixture.
     *
     * @param string $filename Fixture filename.
     * @return string
     */
    private function fixture_path(string $filename): string {
        return __DIR__ . '/fixtures/' . $filename;
    }
}
