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

use booktool_epubimport\toc_mapper;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * PHPUnit coverage for TOC-to-chapter mapping.
 *
 * @package    booktool_epubimport
 * @copyright  2013-2018 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(toc_mapper::class)]
final class toc_mapper_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    public function test_basic_chapter_mapping(): void {
        $toc = [
            $this->toc_entry('Contents', '', 1, [
                $this->toc_entry('Chapter 1', 'text/chapter1.xhtml', 2),
                $this->toc_entry('Chapter 2', 'text/chapter2.xhtml', 2),
            ]),
        ];
        $spine = [
            $this->spine_item('chapter1', 'text/chapter1.xhtml'),
            $this->spine_item('chapter1b', 'text/chapter1b.xhtml'),
            $this->spine_item('chapter2', 'text/chapter2.xhtml'),
            $this->spine_item('chapter2b', 'text/chapter2b.xhtml'),
        ];

        $mapper = new toc_mapper($toc, $spine, 'reflowable');
        $plan = $mapper->get_chapter_plan();
        $this->assert_contents_warning($mapper);

        $this->assertCount(2, $plan);
        $this->assertSame('Chapter 1', $plan[0]['title']);
        $this->assertFalse($plan[0]['subchapter']);
        $this->assertSame(0, $plan[0]['spine_start']);
        $this->assertSame(2, $plan[0]['spine_end']);
        $this->assertSame('Chapter 2', $plan[1]['title']);
        $this->assertFalse($plan[1]['subchapter']);
    }

    public function test_subchapter_mapping(): void {
        $toc = [
            $this->toc_entry('Contents', '', 1, [
                $this->toc_entry('Chapter 1', 'text/chapter1.xhtml', 2, [
                    $this->toc_entry('Section 1', 'text/section1.xhtml', 3),
                    $this->toc_entry('Section 2', 'text/section2.xhtml', 3),
                ]),
                $this->toc_entry('Chapter 2', 'text/chapter2.xhtml', 2),
            ]),
        ];
        $spine = [
            $this->spine_item('chapter1', 'text/chapter1.xhtml'),
            $this->spine_item('section1', 'text/section1.xhtml'),
            $this->spine_item('section2', 'text/section2.xhtml'),
            $this->spine_item('chapter2', 'text/chapter2.xhtml'),
        ];

        $mapper = new toc_mapper($toc, $spine, 'reflowable');
        $plan = $mapper->get_chapter_plan();
        $this->assert_contents_warning($mapper);

        $this->assertSame([
            [
                'title' => 'Chapter 1',
                'subchapter' => false,
                'start_href' => 'text/chapter1.xhtml',
                'end_href' => 'text/chapter2.xhtml',
                'spine_start' => 0,
                'spine_end' => 3,
                'toc_level' => 2,
            ],
            [
                'title' => 'Section 1',
                'subchapter' => true,
                'start_href' => 'text/section1.xhtml',
                'end_href' => 'text/section2.xhtml',
                'spine_start' => 1,
                'spine_end' => 2,
                'toc_level' => 3,
            ],
            [
                'title' => 'Section 2',
                'subchapter' => true,
                'start_href' => 'text/section2.xhtml',
                'end_href' => 'text/chapter2.xhtml',
                'spine_start' => 2,
                'spine_end' => 3,
                'toc_level' => 3,
            ],
            [
                'title' => 'Chapter 2',
                'subchapter' => false,
                'start_href' => 'text/chapter2.xhtml',
                'end_href' => '',
                'spine_start' => 3,
                'spine_end' => 4,
                'toc_level' => 2,
            ],
        ], $plan);
    }

    public function test_level4_flattening(): void {
        $toc = [
            $this->toc_entry('Contents', '', 1, [
                $this->toc_entry('Chapter 1', 'text/chapter1.xhtml', 2, [
                    $this->toc_entry('Section 1', 'text/section1.xhtml', 3, [
                        $this->toc_entry('Detail', 'text/detail.xhtml', 4),
                    ]),
                ]),
                $this->toc_entry('Chapter 2', 'text/chapter2.xhtml', 2),
            ]),
        ];
        $spine = [
            $this->spine_item('chapter1', 'text/chapter1.xhtml'),
            $this->spine_item('section1', 'text/section1.xhtml'),
            $this->spine_item('detail', 'text/detail.xhtml'),
            $this->spine_item('chapter2', 'text/chapter2.xhtml'),
        ];

        $mapper = new toc_mapper($toc, $spine, 'reflowable');
        $plan = $mapper->get_chapter_plan();
        $this->assert_contents_warning($mapper);

        $this->assertCount(3, $plan);
        $this->assertSame(['Chapter 1', 'Section 1', 'Chapter 2'], array_column($plan, 'title'));
        $this->assertSame(3, $plan[1]['spine_end']);
    }

    public function test_page_range_calculation(): void {
        $toc = [
            $this->toc_entry('Contents', '', 1, [
                $this->toc_entry('Chapter 1', 'text/chapter1.xhtml', 2),
                $this->toc_entry('Chapter 2', 'text/chapter3.xhtml', 2),
                $this->toc_entry('Chapter 3', 'text/chapter5.xhtml', 2),
            ]),
        ];
        $spine = [
            $this->spine_item('chapter1', 'text/chapter1.xhtml'),
            $this->spine_item('chapter2', 'text/chapter2.xhtml'),
            $this->spine_item('chapter3', 'text/chapter3.xhtml'),
            $this->spine_item('chapter4', 'text/chapter4.xhtml'),
            $this->spine_item('chapter5', 'text/chapter5.xhtml'),
        ];

        $mapper = new toc_mapper($toc, $spine, 'fixed');
        $plan = $mapper->get_chapter_plan();
        $this->assert_contents_warning($mapper);

        $this->assertSame([0, 2, 4], array_column($plan, 'spine_start'));
        $this->assertSame([2, 4, 5], array_column($plan, 'spine_end'));
        $this->assertSame(['text/chapter3.xhtml', 'text/chapter5.xhtml', ''], array_column($plan, 'end_href'));
    }

    public function test_single_level_toc(): void {
        $toc = [
            $this->toc_entry('Intro', 'text/intro.xhtml', 1),
            $this->toc_entry('Middle', 'text/middle.xhtml', 1),
            $this->toc_entry('End', 'text/end.xhtml', 1),
        ];
        $spine = [
            $this->spine_item('intro', 'text/intro.xhtml'),
            $this->spine_item('middle', 'text/middle.xhtml'),
            $this->spine_item('end', 'text/end.xhtml'),
        ];

        $plan = (new toc_mapper($toc, $spine, 'reflowable'))->get_chapter_plan();

        $this->assertCount(3, $plan);
        $this->assertSame(['Intro', 'Middle', 'End'], array_column($plan, 'title'));
        $this->assertSame([false, false, false], array_column($plan, 'subchapter'));
    }

    public function test_empty_toc(): void {
        $spine = [
            $this->spine_item('chapter-one', 'text/chapter-one.xhtml'),
            $this->spine_item('appendix_a', 'text/appendix_a.xhtml'),
            $this->spine_item('closing', 'text/closing.xhtml'),
        ];

        $plan = (new toc_mapper([], $spine, 'reflowable'))->get_chapter_plan();

        $this->assertCount(3, $plan);
        $this->assertSame(['chapter one', 'appendix a', 'closing'], array_column($plan, 'title'));
        $this->assertSame([0, 1, 2], array_column($plan, 'spine_start'));
        $this->assertSame([1, 2, 3], array_column($plan, 'spine_end'));
    }

    public function test_front_matter_handling(): void {
        $toc = [
            $this->toc_entry('Cover', 'text/cover.xhtml', 1),
            $this->toc_entry('Copyright', 'text/copyright.xhtml', 1),
            $this->toc_entry('Contents', '', 1, [
                $this->toc_entry('Chapter 1', 'text/chapter1.xhtml', 2),
                $this->toc_entry('Chapter 2', 'text/chapter2.xhtml', 2),
            ]),
        ];
        $spine = [
            $this->spine_item('cover', 'text/cover.xhtml'),
            $this->spine_item('copyright', 'text/copyright.xhtml'),
            $this->spine_item('chapter1', 'text/chapter1.xhtml'),
            $this->spine_item('chapter2', 'text/chapter2.xhtml'),
        ];

        $mapper = new toc_mapper($toc, $spine, 'reflowable');
        $plan = $mapper->get_chapter_plan();
        $this->assert_contents_warning($mapper);

        $this->assertSame(['Cover', 'Copyright', 'Chapter 1', 'Chapter 2'], array_column($plan, 'title'));
        $this->assertSame([false, false, false, false], array_column($plan, 'subchapter'));
    }

    /**
     * Builds a TOC entry payload for the mapper.
     *
     * @param string $title Entry label.
     * @param string $href Entry href.
     * @param int $level Entry nesting level.
     * @param array<int, array<string, mixed>> $children Child TOC entries.
     * @return array<string, mixed>
     */
    private function toc_entry(string $title, string $href, int $level, array $children = []): array {
        return [
            'title' => $title,
            'href' => $href,
            'level' => $level,
            'children' => $children,
        ];
    }

    /**
     * Builds a spine item payload for the mapper.
     *
     * @param string $id Spine id.
     * @param string $href Spine href.
     * @param string $mediatype Spine media type.
     * @return array<string, string>
     */
    private function spine_item(string $id, string $href, string $mediatype = 'application/xhtml+xml'): array {
        return [
            'id' => $id,
            'href' => $href,
            'media_type' => $mediatype,
        ];
    }

    /**
     * Asserts the expected warning emitted for a non-spine Contents wrapper entry.
     *
     * @param toc_mapper $mapper Mapper under test.
     * @return void
     */
    private function assert_contents_warning(toc_mapper $mapper): void {
        $this->assertSame(
            ['Skipping TOC entry "Contents" because its href was not found in the spine.'],
            $mapper->get_warnings()
        );
        $this->resetDebugging();
    }
}
