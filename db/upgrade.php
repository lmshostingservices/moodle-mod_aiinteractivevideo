<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Upgrade steps for mod_aiinteractivevideo.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Run database migrations from the installed version to this release.
 *
 * @param int $oldversion Installed plugin version.
 * @return bool True on successful upgrade.
 */
function xmldb_aiinteractivevideo_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102700) {
        // Where the video comes from: a YouTube link (all existing activities) or an uploaded MP4 file.
        $table = new xmldb_table('aiinteractivevideo');
        $field = new xmldb_field('videosource', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'youtube', 'introformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026102700, 'aiinteractivevideo');
    }

    return true;
}
