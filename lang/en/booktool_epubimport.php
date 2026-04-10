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
 * @package    booktool_epubimport
 * @copyright  2013-2018 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['chaptersimported'] = '{$a} chapters imported successfully';
$string['confirmreplace'] = 'I understand that replacing will delete the current chapters before import.';
$string['confirmreplaceerror'] = 'Confirm replacement before deleting the current chapters.';
$string['epubfile'] = 'EPUB file';
$string['epubfile_help'] = 'Upload an EPUB file (.epub) to import as book chapters.';
$string['epubimport:import'] = 'Import EPUB content into Book activities';
$string['error:emptyepub'] = 'The EPUB file appears to be empty.';
$string['error:fixedlayoutrender'] = 'Fixed-layout rendering tools are not available or failed. Install Node.js and Playwright, then try again.';
$string['error:importfailed'] = 'The EPUB import could not be completed. Please check the EPUB package and try again.';
$string['error:invalidepub'] = 'Invalid EPUB file. Please upload a valid .epub file.';
$string['error:notoc'] = 'No table of contents found in EPUB.';
$string['fixedlayout'] = 'Fixed layout EPUB detected; importing page images.';
$string['importchapters'] = 'Import chapters from ebook';
$string['importepub'] = 'Import from EPUB';
$string['importing'] = 'Importing';
$string['importingchapters'] = 'Importing chapters from EPUB';
$string['importmode'] = 'Import mode';
$string['importmode_add'] = 'Add chapters to existing book';
$string['importmode_replace'] = 'Replace all existing chapters';
$string['importmodeappend'] = 'Add chapters to this book';
$string['importmodereplace'] = 'Replace all chapters';
$string['importnewbook'] = 'Import ebook as new book';
$string['importsuccessplural'] = 'Imported {$a->count} chapters from a {$a->layout} EPUB.';
$string['importsuccesssingle'] = 'Imported 1 chapter from a {$a->layout} EPUB.';
$string['layoutfixed'] = 'fixed-layout';
$string['layoutreflowable'] = 'reflowable';
$string['mappingwarningsplural'] = '{$a} mapping warnings were recorded.';
$string['mappingwarningssingle'] = '1 mapping warning was recorded.';
$string['pluginname'] = 'EPUB import';
$string['privacy:metadata'] = 'The EPUB import plugin does not store any personal data.';
$string['reflowable'] = 'Reflowable EPUB detected; importing text content.';
