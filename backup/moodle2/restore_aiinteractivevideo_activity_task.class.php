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
 * Restore task for mod_aiinteractivevideo.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/aiinteractivevideo/backup/moodle2/restore_aiinteractivevideo_stepslib.php');

/**
 * Restore task.
 */
class restore_aiinteractivevideo_activity_task extends restore_activity_task {
    /**
     * No specific settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Defines the structure step.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_aiinteractivevideo_activity_structure_step(
            'aiinteractivevideo_structure',
            'aiinteractivevideo.xml'
        ));
    }

    /**
     * Contents to decode.
     *
     * @return array
     */
    public static function define_decode_contents() {
        return [
            new restore_decode_content('aiinteractivevideo', ['intro'], 'aiinteractivevideo'),
        ];
    }

    /**
     * Decoding rules.
     *
     * @return array
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('AIINTERACTIVEVIDEOVIEWBYID', '/mod/aiinteractivevideo/view.php?id=$1', 'course_module'),
            new restore_decode_rule('AIINTERACTIVEVIDEOINDEX', '/mod/aiinteractivevideo/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Restore log rules.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        return [];
    }

    /**
     * Restore log rules for course.
     *
     * @return array
     */
    public static function define_restore_log_rules_for_course() {
        return [];
    }
}
