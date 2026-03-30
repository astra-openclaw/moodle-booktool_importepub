<?php
declare(strict_types=1);
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

/**
 * Import EPUB controller for existing-book and new-book workflows.
 *
 * @package    booktool
 * @subpackage epubimport
 * @copyright  2013-2018 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use booktool_epubimport\epub_parser;
use booktool_epubimport\fixed_layout_importer;
use booktool_epubimport\reflowable_importer;
use booktool_epubimport\toc_mapper;
// Moodle core classes live in the global namespace — no `use` needed.
// context_course, context_module, moodle_url, stdClass, stored_file, Throwable

require(__DIR__ . '/../../../../config.php');
require_once(__DIR__ . '/../../lib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once(__DIR__ . '/import_form.php');

/** @var moodle_database $DB */
global $DB, $OUTPUT, $PAGE;

const BOOKTOOL_IMPORTEPUB_WORKFLOW_EXISTING = 'existing';
const BOOKTOOL_IMPORTEPUB_WORKFLOW_NEWBOOK = 'newbook';
const BOOKTOOL_IMPORTEPUB_MODE_APPEND = 'append';
const BOOKTOOL_IMPORTEPUB_MODE_REPLACE = 'replace';

$id = optional_param('id', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$sectionnum = optional_param('section', -1, PARAM_INT);
$workflow = booktool_epubimport_normalise_workflow(
    optional_param(
        'workflow',
        $id > 0 ? BOOKTOOL_IMPORTEPUB_WORKFLOW_EXISTING : BOOKTOOL_IMPORTEPUB_WORKFLOW_NEWBOOK,
        PARAM_ALPHA
    )
);

$book = null;
$cm = null;
$course = null;
$context = null;
$cancelurl = null;
$heading = '';
$formdata = [
    'workflow' => $workflow,
    'id' => $id,
    'courseid' => $courseid,
    'section' => $sectionnum,
];
$pageparams = ['workflow' => $workflow];

if ($workflow === BOOKTOOL_IMPORTEPUB_WORKFLOW_EXISTING) {
    if ($id <= 0) {
        throw new moodle_exception('missingparam', 'error', '', 'id');
    }

    $cm = get_coursemodule_from_id('book', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
    $context = context_module::instance($cm->id);

    require_login($course, false, $cm);
    require_capability('mod/book:edit', $context);
    require_capability('booktool/epubimport:import', $context);

    $sectionnum = booktool_epubimport_resolve_section_number($cm);
    $courseid = (int)$course->id;
    $cancelurl = new moodle_url('/mod/book/view.php', ['id' => $cm->id]);
    $heading = booktool_epubimport_local_string('importchapters', 'Import chapters from ebook');
    $pageparams['id'] = $cm->id;
    $formdata['courseid'] = $courseid;
    $formdata['section'] = $sectionnum;
} else {
    if ($id > 0) {
        $cm = get_coursemodule_from_id('book', $id, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $context = context_module::instance($cm->id);

        require_login($course, false, $cm);
        require_capability('mod/book:edit', $context);
        require_capability('mod/book:addinstance', $context);
        require_capability('booktool/epubimport:import', $context);

        $courseid = (int)$course->id;
        if ($sectionnum < 0) {
            $sectionnum = booktool_epubimport_resolve_section_number($cm);
        }

        $cancelurl = new moodle_url('/mod/book/view.php', ['id' => $cm->id]);
        $pageparams['id'] = $cm->id;
    } else {
        if ($courseid <= 0) {
            throw new moodle_exception('missingparam', 'error', '', 'courseid');
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $coursecontext = context_course::instance($course->id);

        require_login($course);
        require_capability('mod/book:addinstance', $coursecontext);
        require_capability('booktool/epubimport:import', $coursecontext);

        $context = $coursecontext;
        if ($sectionnum < 0) {
            $sectionnum = 0;
        }

        $cancelurl = new moodle_url('/course/view.php', ['id' => $course->id]);
        $pageparams['courseid'] = $course->id;
    }

    $heading = booktool_epubimport_local_string('importepub', 'Import ebook as new book');
    $formdata['courseid'] = (int)$course->id;
    $formdata['section'] = $sectionnum;
}

$PAGE->set_url('/mod/book/tool/epubimport/index.php', $pageparams + ['section' => $sectionnum]);
$PAGE->set_heading($course->fullname);
$PAGE->set_title($heading);

if ($cm !== null && property_exists($PAGE, 'activityheader') && $PAGE->activityheader !== null) {
    $PAGE->activityheader->set_attrs([
        'hidecompletion' => true,
        'description' => '',
    ]);
}

$mform = new booktool_epubimport_form(null, $formdata);

if ($mform->is_cancelled()) {
    redirect($cancelurl);
}

if (($data = $mform->get_data()) !== null) {
    $redirecturl = null;
    $redirectmessage = '';
    $redirecttype = notification::NOTIFY_SUCCESS;
    $parser = null;

    try {
        $file = booktool_epubimport_get_uploaded_file((int)($data->importfile ?? 0));
        $parser = new epub_parser($file, make_request_directory());
        $parser->extract();

        $targetcm = $cm;
        $targetbook = $book;
        $targetcontext = $context;

        if ($workflow === BOOKTOOL_IMPORTEPUB_WORKFLOW_NEWBOOK) {
            $moduleinfo = booktool_epubimport_build_moduleinfo(
                $parser->get_metadata(),
                $file,
                $course,
                max(0, $sectionnum)
            );
            $moduleinfo = add_moduleinfo($moduleinfo, $course);

            $targetcm = get_coursemodule_from_id('book', (int)$moduleinfo->coursemodule, 0, false, MUST_EXIST);
            $targetbook = $DB->get_record('book', ['id' => $moduleinfo->instance], '*', MUST_EXIST);
            $targetcontext = context_module::instance($targetcm->id);
        }

        if ($targetbook === null || $targetcontext === null || $targetcm === null) {
            throw new moodle_exception('invalidparameter');
        }

        $mapper = new toc_mapper($parser->get_toc(), $parser->get_spine(), $parser->get_layout());

        if ($workflow === BOOKTOOL_IMPORTEPUB_WORKFLOW_EXISTING
                && ($data->importmode ?? BOOKTOOL_IMPORTEPUB_MODE_APPEND) === BOOKTOOL_IMPORTEPUB_MODE_REPLACE) {
            booktool_epubimport_reset_book_contents($targetbook, $targetcontext);
        }

        $importer = $parser->get_layout() === 'fixed'
            ? new fixed_layout_importer($parser, $mapper, $targetbook, $targetcontext)
            : new reflowable_importer($parser, $mapper, $targetbook, $targetcontext);

        $chapters = $importer->import();
        booktool_epubimport_bump_revision((int)$targetbook->id);

        $redirecturl = new moodle_url('/mod/book/view.php', ['id' => $targetcm->id]);
        $redirectmessage = booktool_epubimport_build_success_message(
            count($chapters),
            $parser->get_layout(),
            $mapper->get_warnings()
        );
    } catch (Throwable $exception) {
        $redirecturl = $PAGE->url;
        $redirectmessage = $exception->getMessage();
        $redirecttype = notification::NOTIFY_ERROR;
    } finally {
        if ($parser instanceof epub_parser) {
            $parser->cleanup();
        }
    }

    redirect($redirecturl, $redirectmessage, null, $redirecttype);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
$mform->display();
echo $OUTPUT->footer();

/**
 * Normalises the requested workflow to a supported value.
 *
 * @param string $workflow Submitted workflow.
 * @return string
 */
function booktool_epubimport_normalise_workflow(string $workflow): string {
    $workflow = strtolower(trim($workflow));
    if ($workflow === BOOKTOOL_IMPORTEPUB_WORKFLOW_NEWBOOK) {
        return BOOKTOOL_IMPORTEPUB_WORKFLOW_NEWBOOK;
    }

    return BOOKTOOL_IMPORTEPUB_WORKFLOW_EXISTING;
}

/**
 * Resolves the course section number for an existing course module.
 *
 * @param stdClass $cm Course module record.
 * @return int
 */
function booktool_epubimport_resolve_section_number(stdClass $cm): int {
    global $DB;

    if (empty($cm->section)) {
        return 0;
    }

    $sectionnum = $DB->get_field('course_sections', 'section', ['id' => $cm->section]);

    return $sectionnum === false ? 0 : (int)$sectionnum;
}

/**
 * Retrieves the uploaded EPUB from the user's draft area.
 *
 * @param int $draftitemid Draft item id from the filepicker.
 * @return stored_file
 */
function booktool_epubimport_get_uploaded_file(int $draftitemid): stored_file {
    global $USER;

    if ($draftitemid <= 0) {
        throw new moodle_exception('required');
    }

    $fs = get_file_storage();
    $usercontext = context_user::instance($USER->id);
    $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id DESC', false);
    if ($files === []) {
        throw new moodle_exception('required');
    }

    $file = reset($files);
    if (!$file instanceof stored_file) {
        throw new moodle_exception('required');
    }

    return $file;
}

/**
 * Builds the moduleinfo payload used by add_moduleinfo() for a new Book.
 *
 * @param object $metadata Parsed EPUB metadata.
 * @param stored_file $file Uploaded EPUB file.
 * @param stdClass $course Course record.
 * @param int $sectionnum Target course section number.
 * @return stdClass
 */
function booktool_epubimport_build_moduleinfo(
    object $metadata,
    stored_file $file,
    stdClass $course,
    int $sectionnum
): stdClass {
    global $DB;

    $title = trim((string)($metadata->title ?? ''));
    if ($title === '') {
        $title = pathinfo($file->get_filename(), PATHINFO_FILENAME);
    }

    if ($title === '') {
        $title = booktool_epubimport_local_string('importepub', 'Import ebook as new book');
    }

    $moduleinfo = new stdClass();
    $moduleinfo->modulename = 'book';
    $moduleinfo->module = $DB->get_field('modules', 'id', ['name' => 'book'], MUST_EXIST);
    $moduleinfo->course = (int)$course->id;
    $moduleinfo->section = max(0, $sectionnum);
    $moduleinfo->visible = 1;
    $moduleinfo->visibleoncoursepage = 1;
    $moduleinfo->name = $title;
    $moduleinfo->intro = '';
    $moduleinfo->introformat = FORMAT_HTML;
    $moduleinfo->introeditor = [
        'text' => '',
        'format' => FORMAT_HTML,
        'itemid' => 0,
    ];
    $moduleinfo->numbering = defined('BOOK_NUM_NONE') ? BOOK_NUM_NONE : 0;
    $moduleinfo->customtitles = 0;
    $moduleinfo->groupmode = 0;
    $moduleinfo->groupingid = 0;

    return $moduleinfo;
}

/**
 * Deletes current chapter rows and stored files before a replace-all import.
 *
 * @param stdClass $book Book record to reset.
 * @param context_module $context Module context for chapter files.
 */
function booktool_epubimport_reset_book_contents(stdClass $book, context_module $context): void {
    global $DB;

    $fs = get_file_storage();
    $chapters = $DB->get_records('book_chapters', ['bookid' => $book->id], '', 'id');
    foreach ($chapters as $chapter) {
        $fs->delete_area_files($context->id, 'mod_book', 'chapter', (int)$chapter->id);
    }

    $DB->delete_records('book_chapters', ['bookid' => $book->id]);
}

/**
 * Increments the book revision after the import has changed chapter content.
 *
 * @param int $bookid Book instance id.
 */
function booktool_epubimport_bump_revision(int $bookid): void {
    global $DB;

    $book = $DB->get_record('book', ['id' => $bookid], 'id, revision', MUST_EXIST);
    $DB->set_field('book', 'revision', (int)$book->revision + 1, ['id' => $book->id]);
}

/**
 * Builds the redirect notification shown after a completed import.
 *
 * @param int $chaptercount Number of created chapters.
 * @param string $layout Detected EPUB layout.
 * @param array<int, string> $warnings Non-fatal mapper warnings.
 * @return string
 */
function booktool_epubimport_build_success_message(int $chaptercount, string $layout, array $warnings): string {
    $message = "Imported {$chaptercount} chapter";
    if ($chaptercount !== 1) {
        $message .= 's';
    }

    $message .= " from a {$layout} EPUB.";
    if ($warnings !== []) {
        $warningcount = count($warnings);
        $message .= " {$warningcount} mapping warning";
        if ($warningcount !== 1) {
            $message .= 's';
        }
        $message .= ' were recorded.';
    }

    return $message;
}
