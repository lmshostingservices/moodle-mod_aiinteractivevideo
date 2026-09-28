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
 * Restore structure for mod_aiinteractivevideo.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore structure step.
 */
class restore_aiinteractivevideo_activity_structure_step extends restore_activity_structure_step {
    /**
     * Defines the paths.
     *
     * @return array
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');
        $paths = [];
        $paths[] = new restore_path_element('aiinteractivevideo', '/activity/aiinteractivevideo');
        $paths[] = new restore_path_element('aiinteractivevideo_section', '/activity/aiinteractivevideo/sections/section');
        if ($userinfo) {
            $paths[] = new restore_path_element('aiinteractivevideo_attempt', '/activity/aiinteractivevideo/attempts/attempt');
            $paths[] = new restore_path_element(
                'aiinteractivevideo_response',
                '/activity/aiinteractivevideo/attempts/attempt/responses/response'
            );
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restores the instance.
     *
     * @param array $data
     */
    protected function process_aiinteractivevideo($data) {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        $data->timemodified = time();
        // A queued AI build does not travel with the backup: the built interactions are restored instead.
        $data->genstatus = 0;
        $newid = $DB->insert_record('aiinteractivevideo', $data);
        $this->apply_activity_instance($newid);
    }

    /**
     * Restores a section.
     *
     * @param array $data
     */
    protected function process_aiinteractivevideo_section($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->aiinteractivevideoid = $this->get_new_parentid('aiinteractivevideo');
        $newid = $DB->insert_record('aiinteractivevideo_section', $data);
        $this->set_mapping('aiinteractivevideo_section', $oldid, $newid);
    }

    /**
     * Restores an attempt.
     *
     * @param array $data
     */
    protected function process_aiinteractivevideo_attempt($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->aiinteractivevideoid = $this->get_new_parentid('aiinteractivevideo');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return;
        }
        $newid = $DB->insert_record('aiinteractivevideo_attempt', $data);
        $this->set_mapping('aiinteractivevideo_attempt', $oldid, $newid);
    }

    /**
     * Restores a response.
     *
     * @param array $data
     */
    protected function process_aiinteractivevideo_response($data) {
        global $DB;
        $data = (object)$data;
        $data->attemptid = $this->get_new_parentid('aiinteractivevideo_attempt');
        $data->sectionid = $this->get_mappingid('aiinteractivevideo_section', $data->sectionid);
        if (!$data->attemptid || !$data->sectionid) {
            return;
        }
        $DB->insert_record('aiinteractivevideo_response', $data);
    }

    /**
     * Restores files after the structure.
     */
    protected function after_execute() {
        $this->add_related_files('mod_aiinteractivevideo', 'intro', null);
    }
}
