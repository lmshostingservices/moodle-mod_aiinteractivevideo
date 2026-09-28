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

namespace mod_aiinteractivevideo\task;

use mod_aiinteractivevideo\local\manager;

/**
 * Ad hoc task: builds the interactions with the site's AI provider after the activity is saved.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_interactions extends \core\task\adhoc_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskgenerate', 'mod_aiinteractivevideo');
    }

    /**
     * Runs the generation unless it has already been done from the builder page.
     */
    public function execute() {
        global $DB;
        $data = $this->get_custom_data();
        $instance = $DB->get_record('aiinteractivevideo', ['id' => (int)$data->instanceid]);
        if (!$instance || (int)$instance->genstatus !== manager::GEN_PENDING) {
            return;
        }
        try {
            $count = manager::run_ai($instance);
            mtrace('AI interactive video ' . $instance->id . ': ' . $count . ' interactions generated.');
        } catch (\moodle_exception $e) {
            // Existing interactions stay in place; the teacher sees the reason on the activity and builder pages.
            mtrace('AI interactive video ' . $instance->id . ': LMS Labs AI generation failed: ' . $e->getMessage());
        }
    }
}
