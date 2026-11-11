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

    if ($oldversion < 2026111100) {
        // Video engagement: which seconds of the video each attempt really played.
        $table = new xmldb_table('aiinteractivevideo_attempt');
        $field = new xmldb_field('watchedranges', XMLDB_TYPE_TEXT, null, null, null, null, null, 'activetime');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('watchedsecs', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'watchedranges');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Older attempts only know the furthest point reached: count everything up to it as watched.
        $rs = $DB->get_recordset_select('aiinteractivevideo_attempt', 'maxwatched > 0', null, '', 'id, maxwatched');
        foreach ($rs as $a) {
            $end = (int)floor((float)$a->maxwatched);
            if ($end > 0) {
                $DB->update_record('aiinteractivevideo_attempt', (object)['id' => $a->id,
                    'watchedranges' => '0-' . $end, 'watchedsecs' => $end]);
            }
        }
        $rs->close();
        upgrade_mod_savepoint(true, 2026111100, 'aiinteractivevideo');
    }

    return true;
}
