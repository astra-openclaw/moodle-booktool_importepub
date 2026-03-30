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
 * Library hooks for the EPUB import book tool.
 *
 * @package    booktool
 * @subpackage epubimport
 * @copyright  2013-2018 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds the EPUB import action to the book settings navigation.
 *
 * @param settings_navigation $settings Settings navigation.
 * @param navigation_node $node Current navigation node.
 */
function booktool_epubimport_extend_settings_navigation(settings_navigation $settings, navigation_node $node): void {
    $page = $settings->get_page();
    if (empty($page->cm) || empty($page->cm->context)) {
        return;
    }

    if (!has_capability('booktool/epubimport:import', $page->cm->context)) {
        return;
    }

    $url = new moodle_url('/mod/book/tool/epubimport/index.php', ['id' => $page->cm->id]);
    $node->add(
        get_string('importepub', 'booktool_epubimport'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'importepub'
    );
}
