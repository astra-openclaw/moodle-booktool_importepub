<?php
declare(strict_types=1);

namespace booktool_epubimport;

use InvalidArgumentException;

defined('MOODLE_INTERNAL') || die();

/**
 * Maps EPUB TOC entries onto Moodle Book chapters and subchapters.
 */
final class toc_mapper {
    /**
     * @var array<int, array{title: string, href: string, level: int, children: array}>
     */
    private array $toc;

    /**
     * @var array<int, array{id: string, href: string, media_type: string}>
     */
    private array $spine;

    /** @var string EPUB layout mode ('fixed' or 'reflowable'). */
    private string $layout;

    /** @var array<string, int> Exact spine lookups by OPF-relative href. */
    private array $spineindexbyhref = [];

    /** @var array<string, int> Fallback spine lookups by filename only. */
    private array $spineindexbyfile = [];

    /** @var array<int, string> Non-fatal mapping warnings. */
    private array $warnings = [];

    /**
     * @var array<int, array{
     *     title: string,
     *     subchapter: bool,
     *     start_href: string,
     *     end_href: string,
     *     spine_start: int,
     *     spine_end: int,
     *     toc_level: int
     * }>|null
     */
    private ?array $chapterplan = null;

    /**
     * Constructor.
     *
     * @param array<int, array{title: string, href: string, level: int, children: array}> $toc
     * @param array<int, array{id: string, href: string, media_type: string}> $spine
     * @param string $layout EPUB layout mode ('fixed' or 'reflowable').
     */
    public function __construct(array $toc, array $spine, string $layout) {
        $layout = strtolower(trim($layout));
        if ($layout !== 'fixed' && $layout !== 'reflowable') {
            throw new InvalidArgumentException('Layout must be either "fixed" or "reflowable".');
        }

        $this->toc = $toc;
        $this->spine = array_values($spine);
        $this->layout = $layout;

        $this->build_spine_lookups();
    }

    /**
     * Returns the flat Moodle Book chapter/subchapter plan.
     *
     * @return array<int, array{
     *     title: string,
     *     subchapter: bool,
     *     start_href: string,
     *     end_href: string,
     *     spine_start: int,
     *     spine_end: int,
     *     toc_level: int
     * }>
     */
    public function get_chapter_plan(): array {
        if ($this->chapterplan !== null) {
            return $this->chapterplan;
        }

        if ($this->spine === []) {
            $this->chapterplan = [];
            return $this->chapterplan;
        }

        if ($this->toc === []) {
            $this->chapterplan = $this->build_spine_fallback_plan();
            return $this->chapterplan;
        }

        if ($this->max_toc_level($this->toc) <= 1) {
            $this->chapterplan = $this->build_single_level_plan();
            return $this->chapterplan;
        }

        $this->chapterplan = $this->build_hierarchical_plan();

        return $this->chapterplan;
    }

    /**
     * Returns non-fatal warnings generated during mapping.
     *
     * @return array<int, string>
     */
    public function get_warnings(): array {
        return $this->warnings;
    }

    /**
     * Builds one chapter per spine entry when the EPUB has no usable TOC.
     *
     * @return array<int, array{
     *     title: string,
     *     subchapter: bool,
     *     start_href: string,
     *     end_href: string,
     *     spine_start: int,
     *     spine_end: int,
     *     toc_level: int
     * }>
     */
    private function build_spine_fallback_plan(): array {
        $plan = [];

        foreach ($this->spine as $index => $item) {
            $href = trim((string)($item['href'] ?? ''));
            $plan[] = $this->create_plan_entry(
                $this->title_from_href($href),
                false,
                $href,
                $index,
                $index + 1,
                0
            );
        }

        return $plan;
    }

    /**
     * Builds chapter mappings when the TOC has only a single level.
     *
     * @return array<int, array{
     *     title: string,
     *     subchapter: bool,
     *     start_href: string,
     *     end_href: string,
     *     spine_start: int,
     *     spine_end: int,
     *     toc_level: int
     * }>
     */
    private function build_single_level_plan(): array {
        $entries = $this->flatten_entries($this->toc);
        $plan = [];

        foreach ($entries as $index => $entry) {
            $spineindex = $this->resolve_entry_spine_index($entry);
            if ($spineindex === null) {
                $this->warn_missing_spine_href($entry);
                continue;
            }

            $spineend = $this->find_next_sibling_boundary($entries, $index, count($this->spine));
            $plan[] = $this->create_plan_entry(
                $this->entry_title($entry),
                false,
                $this->entry_href($entry),
                $spineindex,
                $spineend,
                $this->entry_level($entry),
                $index
            );
        }

        usort($plan, [$this, 'compare_plan_entries']);

        return array_map(
            static function(array $entry): array {
                unset($entry['_sequence']);
                return $entry;
            },
            $plan
        );
    }

    /**
     * Builds chapter mappings from a hierarchical TOC.
     *
     * @return array<int, array{
     *     title: string,
     *     subchapter: bool,
     *     start_href: string,
     *     end_href: string,
     *     spine_start: int,
     *     spine_end: int,
     *     toc_level: int
     * }>
     */
    private function build_hierarchical_plan(): array {
        $plan = [];
        $this->collect_hierarchical_entries($this->toc, count($this->spine), $plan);

        usort($plan, [$this, 'compare_plan_entries']);

        return array_map(
            static function(array $entry): array {
                unset($entry['_sequence']);
                return $entry;
            },
            $plan
        );
    }

    /**
     * Recursively collects TOC entries that should become chapters or subchapters.
     *
     * @param array<int, array{title: string, href: string, level: int, children: array}> $entries
     * @param int $parentend Exclusive parent spine boundary.
     * @param array<int, array<string, mixed>> $plan
     */
    private function collect_hierarchical_entries(array $entries, int $parentend, array &$plan): void {
        foreach ($entries as $index => $entry) {
            $spineend = $this->find_next_sibling_boundary($entries, $index, $parentend);

            if ($this->should_map_entry($entry)) {
                $spineindex = $this->resolve_entry_spine_index($entry);
                if ($spineindex === null) {
                    $this->warn_missing_spine_href($entry);
                } else {
                    $plan[] = $this->create_plan_entry(
                        $this->entry_title($entry),
                        $this->entry_level($entry) === 3,
                        $this->entry_href($entry),
                        $spineindex,
                        $spineend,
                        $this->entry_level($entry),
                        count($plan)
                    );
                }
            }

            $children = $this->entry_children($entry);
            if ($children !== []) {
                $this->collect_hierarchical_entries($children, $spineend, $plan);
            }
        }
    }

    /**
     * Determines whether a TOC entry should be emitted into the chapter plan.
     */
    private function should_map_entry(array $entry): bool {
        $level = $this->entry_level($entry);

        if ($level >= 4) {
            return false;
        }

        if ($level === 1) {
            return $this->entry_children($entry) === [] || $this->is_front_or_end_matter($this->entry_title($entry));
        }

        return $level === 2 || $level === 3;
    }

    /**
     * Finds the exclusive spine boundary for an entry within its sibling list.
     *
     * @param array<int, array{title: string, href: string, level: int, children: array}> $entries
     * @param int $currentindex Index of the current entry in the sibling list.
     * @param int $fallbackend Parent boundary if there is no later sibling boundary.
     * @return int
     */
    private function find_next_sibling_boundary(array $entries, int $currentindex, int $fallbackend): int {
        $count = count($entries);
        for ($index = $currentindex + 1; $index < $count; $index++) {
            $boundary = $this->resolve_branch_spine_index($entries[$index]);
            if ($boundary !== null) {
                return $boundary;
            }
        }

        return $fallbackend;
    }

    /**
     * Returns the first usable spine index for an entry or any descendant in its branch.
     */
    private function resolve_branch_spine_index(array $entry): ?int {
        $spineindex = $this->resolve_entry_spine_index($entry);
        if ($spineindex !== null) {
            return $spineindex;
        }

        foreach ($this->entry_children($entry) as $child) {
            $childindex = $this->resolve_branch_spine_index($child);
            if ($childindex !== null) {
                return $childindex;
            }
        }

        return null;
    }

    /**
     * Returns the spine index for a TOC entry's own href.
     */
    private function resolve_entry_spine_index(array $entry): ?int {
        return $this->resolve_href_to_spine_index($this->entry_href($entry));
    }

    /**
     * Resolves an EPUB href to a spine index.
     *
     * Matching first uses the full OPF-relative path, then falls back to filename-only matching
     * to tolerate nav documents that point at equivalent files through a different relative path.
     */
    private function resolve_href_to_spine_index(string $href): ?int {
        $href = trim($href);
        if ($href === '' || $this->has_uri_scheme($href)) {
            return null;
        }

        [$path] = $this->split_fragment($href);
        $path = $this->normalise_path($path);
        if ($path === '') {
            return null;
        }

        if (array_key_exists($path, $this->spineindexbyhref)) {
            return $this->spineindexbyhref[$path];
        }

        $filename = basename($path);
        if ($filename !== '' && array_key_exists($filename, $this->spineindexbyfile)) {
            return $this->spineindexbyfile[$filename];
        }

        return null;
    }

    /**
     * Builds exact and filename-only spine lookups.
     */
    private function build_spine_lookups(): void {
        foreach ($this->spine as $index => $item) {
            $href = trim((string)($item['href'] ?? ''));
            if ($href === '' || $this->has_uri_scheme($href)) {
                continue;
            }

            [$path] = $this->split_fragment($href);
            $path = $this->normalise_path($path);
            if ($path === '') {
                continue;
            }

            if (!array_key_exists($path, $this->spineindexbyhref)) {
                $this->spineindexbyhref[$path] = $index;
            }

            $filename = basename($path);
            if ($filename !== '' && !array_key_exists($filename, $this->spineindexbyfile)) {
                $this->spineindexbyfile[$filename] = $index;
            }
        }
    }

    /**
     * Creates a final chapter plan entry.
     *
     * @return array{
     *     title: string,
     *     subchapter: bool,
     *     start_href: string,
     *     end_href: string,
     *     spine_start: int,
     *     spine_end: int,
     *     toc_level: int,
     *     _sequence?: int
     * }
     */
    private function create_plan_entry(
        string $title,
        bool $subchapter,
        string $starthref,
        int $spinestart,
        int $spineend,
        int $toclevel,
        ?int $sequence = null
    ): array {
        if ($spineend < $spinestart) {
            $this->warn(
                'TOC range for "' . ($title !== '' ? $title : $starthref) . '" resolved out of spine order; ' .
                'clamping end boundary to the start index.'
            );
            $spineend = $spinestart;
        }

        $entry = [
            'title' => $title !== '' ? $title : $this->title_from_href($starthref),
            'subchapter' => $subchapter,
            'start_href' => $starthref,
            'end_href' => $this->spine[$spineend]['href'] ?? '',
            'spine_start' => $spinestart,
            'spine_end' => $spineend,
            'toc_level' => $toclevel,
        ];

        if ($sequence !== null) {
            $entry['_sequence'] = $sequence;
        }

        return $entry;
    }

    /**
     * Compares plan entries by spine position while preserving TOC order for ties.
     */
    private function compare_plan_entries(array $left, array $right): int {
        $startcompare = $left['spine_start'] <=> $right['spine_start'];
        if ($startcompare !== 0) {
            return $startcompare;
        }

        return ($left['_sequence'] ?? 0) <=> ($right['_sequence'] ?? 0);
    }

    /**
     * Flattens a TOC tree into a single ordered list.
     *
     * @param array<int, array{title: string, href: string, level: int, children: array}> $entries
     * @return array<int, array{title: string, href: string, level: int, children: array}>
     */
    private function flatten_entries(array $entries): array {
        $flattened = [];

        foreach ($entries as $entry) {
            $flattened[] = $entry;

            $children = $this->entry_children($entry);
            if ($children !== []) {
                $flattened = array_merge($flattened, $this->flatten_entries($children));
            }
        }

        return $flattened;
    }

    /**
     * Returns the maximum TOC level present in a TOC tree.
     *
     * @param array<int, array{title: string, href: string, level: int, children: array}> $entries
     * @return int
     */
    private function max_toc_level(array $entries): int {
        $maxlevel = 0;

        foreach ($entries as $entry) {
            $maxlevel = max($maxlevel, $this->entry_level($entry));

            $children = $this->entry_children($entry);
            if ($children !== []) {
                $maxlevel = max($maxlevel, $this->max_toc_level($children));
            }
        }

        return $maxlevel;
    }

    /**
     * Returns whether the title matches a standard front/end matter section.
     */
    private function is_front_or_end_matter(string $title): bool {
        $title = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', ' ', $title)));

        return in_array($title, [
            'cover',
            'title page',
            'copyright',
            'table of contents',
            'contents',
            'glossary',
            'index',
        ], true);
    }

    /**
     * Builds a readable fallback title from a spine href.
     */
    private function title_from_href(string $href): string {
        [$path] = $this->split_fragment($href);
        $path = $this->normalise_path($path);
        if ($path === '') {
            return 'Untitled Chapter';
        }

        $title = pathinfo($path, PATHINFO_FILENAME);
        if ($title === '') {
            $title = basename($path);
        }

        $title = str_replace(['_', '-'], ' ', $title);
        $title = trim((string)preg_replace('/\s+/', ' ', $title));

        return $title !== '' ? $title : 'Untitled Chapter';
    }

    /**
     * Records a warning about a TOC href that does not resolve to the spine.
     */
    private function warn_missing_spine_href(array $entry): void {
        $href = $this->entry_href($entry);
        $label = $this->entry_title($entry);
        $message = 'Skipping TOC entry "' . ($label !== '' ? $label : '[untitled]') . '" because its href was not found in the spine';

        if ($href !== '') {
            $message .= ': ' . $href;
        } else {
            $message .= '.';
        }

        $this->warn($message);
    }

    /**
     * Stores a non-fatal warning and forwards it to Moodle developer debugging when available.
     */
    private function warn(string $message): void {
        $this->warnings[] = $message;

        if (function_exists('debugging')) {
            debugging($message, defined('DEBUG_DEVELOPER') ? DEBUG_DEVELOPER : 0);
        }
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
     * Returns whether a href starts with a URI scheme.
     */
    private function has_uri_scheme(string $href): bool {
        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $href) === 1;
    }

    /**
     * Returns a TOC entry title.
     */
    private function entry_title(array $entry): string {
        return trim((string)($entry['title'] ?? ''));
    }

    /**
     * Returns a TOC entry href.
     */
    private function entry_href(array $entry): string {
        return trim((string)($entry['href'] ?? ''));
    }

    /**
     * Returns a TOC entry level.
     */
    private function entry_level(array $entry): int {
        $level = (int)($entry['level'] ?? 1);

        return $level > 0 ? $level : 1;
    }

    /**
     * Returns TOC children for an entry.
     *
     * @return array<int, array{title: string, href: string, level: int, children: array}>
     */
    private function entry_children(array $entry): array {
        return isset($entry['children']) && is_array($entry['children']) ? array_values($entry['children']) : [];
    }
}
