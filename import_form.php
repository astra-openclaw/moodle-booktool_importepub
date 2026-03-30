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

/**
 * Import EPUB form.
 *
 * @package    booktool
 * @subpackage importepub
 * @copyright  2013-2018 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Returns a plugin string when available, with an English fallback for staged builds.
 *
 * @param string $identifier String identifier.
 * @param string $fallback English fallback.
 * @return string
 */
function booktool_importepub_local_string(string $identifier, string $fallback): string {
    $stringmanager = get_string_manager();
    if ($stringmanager->string_exists($identifier, 'booktool_importepub')) {
        return get_string($identifier, 'booktool_importepub');
    }

    return $fallback;
}

/**
 * Moodle form for EPUB upload and import mode selection.
 */
class booktool_importepub_form extends moodleform {
    /** @var string Existing-book workflow identifier. */
    private const WORKFLOW_EXISTING = 'existing';

    /** @var string Append chapter import mode. */
    private const MODE_APPEND = 'append';

    /** @var string Replace-all chapter import mode. */
    private const MODE_REPLACE = 'replace';

    /**
     * Defines the import form fields.
     */
    public function definition() {
        $mform = $this->_form;
        $data = $this->_customdata ?? [];
        $workflow = (string)($data['workflow'] ?? self::WORKFLOW_EXISTING);

        $heading = $workflow === self::WORKFLOW_EXISTING
            ? booktool_importepub_local_string('importchapters', 'Import chapters from ebook')
            : booktool_importepub_local_string('importepub', 'Import ebook as new book');

        $mform->addElement('header', 'general', $heading);
        $mform->addElement(
            'filepicker',
            'importfile',
            booktool_importepub_local_string('epubfile', 'EPUB ebook'),
            null,
            ['accepted_types' => ['.epub']]
        );
        $mform->addRule('importfile', null, 'required');

        if ($workflow === self::WORKFLOW_EXISTING) {
            $mform->addElement(
                'select',
                'importmode',
                booktool_importepub_local_string('importmode', 'Import mode'),
                [
                    self::MODE_APPEND => booktool_importepub_local_string(
                        'importmodeappend',
                        'Add chapters to this book'
                    ),
                    self::MODE_REPLACE => booktool_importepub_local_string(
                        'importmodereplace',
                        'Replace all chapters'
                    ),
                ]
            );
            $mform->setDefault('importmode', self::MODE_APPEND);

            $mform->addElement(
                'advcheckbox',
                'confirmreplace',
                '',
                booktool_importepub_local_string(
                    'confirmreplace',
                    'I understand that replacing will delete the current chapters before import.'
                )
            );
            $mform->disabledIf('confirmreplace', 'importmode', 'neq', self::MODE_REPLACE);
        } else {
            $mform->addElement('hidden', 'importmode', self::MODE_APPEND);
            $mform->setType('importmode', PARAM_ALPHA);
        }

        $mform->addElement('hidden', 'workflow');
        $mform->setType('workflow', PARAM_ALPHA);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'section');
        $mform->setType('section', PARAM_INT);

        $this->add_action_buttons(true, get_string('import'));
        $this->set_data($data);
    }

    /**
     * Validates the uploaded draft EPUB.
     *
     * @param array $data Submitted form data.
     * @param array $files Uploaded file array.
     * @return array<string, string>
     */
    public function validation($data, $files) {
        global $USER;

        $errors = parent::validation($data, $files);
        if ($errors) {
            return $errors;
        }

        $draftitemid = (int)($data['importfile'] ?? 0);
        if ($draftitemid <= 0) {
            $errors['importfile'] = get_string('required');
            return $errors;
        }

        $fs = get_file_storage();
        $usercontext = context_user::instance($USER->id);
        $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id DESC', false);
        if ($draftfiles === []) {
            $errors['importfile'] = get_string('required');
            return $errors;
        }

        $file = reset($draftfiles);
        $filename = (string)$file->get_filename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimetype = (string)$file->get_mimetype();

        if ($extension !== 'epub') {
            $errors['importfile'] = get_string('invalidfiletype', 'error', $filename);
            $fs->delete_area_files($usercontext->id, 'user', 'draft', $draftitemid);
        } else if ($mimetype !== 'application/epub+zip' && $mimetype !== 'application/zip') {
            $errors['importfile'] = get_string('invalidfiletype', 'error', $filename);
            $fs->delete_area_files($usercontext->id, 'user', 'draft', $draftitemid);
        }

        if (($data['workflow'] ?? self::WORKFLOW_EXISTING) === self::WORKFLOW_EXISTING
                && ($data['importmode'] ?? self::MODE_APPEND) === self::MODE_REPLACE
                && empty($data['confirmreplace'])) {
            $errors['confirmreplace'] = booktool_importepub_local_string(
                'confirmreplaceerror',
                'Confirm replacement before deleting the current chapters.'
            );
        }

        return $errors;
    }
}
