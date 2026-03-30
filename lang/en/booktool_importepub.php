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
 * English language strings for the EPUB import book tool.
 *
 * @package    booktool
 * @subpackage importepub
 * @copyright  2013-2018 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'EPUB import';
$string['importepub'] = 'Import from EPUB';
$string['importchapters'] = 'Import chapters from ebook';
$string['importing'] = 'Importing';
$string['importingchapters'] = 'Importing chapters from EPUB';
$string['chaptersimported'] = '{$a} chapters imported successfully';
$string['epubfile'] = 'EPUB file';
$string['epubfile_help'] = 'Upload an EPUB file (.epub) to import as book chapters.';
$string['importmode'] = 'Import mode';
$string['importmodeappend'] = 'Add chapters to this book';
$string['importmode_add'] = 'Add chapters to existing book';
$string['importmodereplace'] = 'Replace all chapters';
$string['importmode_replace'] = 'Replace all existing chapters';
$string['confirmreplace'] = 'I understand that replacing will delete the current chapters before import.';
$string['confirmreplaceerror'] = 'Confirm replacement before deleting the current chapters.';
$string['privacy:metadata'] = 'The EPUB import plugin does not store any personal data.';
$string['error:invalidepub'] = 'Invalid EPUB file. Please upload a valid .epub file.';
$string['error:notoc'] = 'No table of contents found in EPUB.';
$string['error:emptyepub'] = 'The EPUB file appears to be empty.';
$string['fixedlayout'] = 'Fixed layout EPUB detected; importing page images.';
$string['reflowable'] = 'Reflowable EPUB detected; importing text content.';
