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

namespace mod_aiinteractivevideo\completion;

use core_completion\activity_custom_completion;
use mod_aiinteractivevideo\local\manager;

/**
 * Custom completion rule: finish the video with every interaction completed.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetches the completion state for a given rule.
     *
     * @param string $rule
     * @return int
     */
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);
        $instance = $DB->get_record('aiinteractivevideo', ['id' => $this->cm->instance], 'id, allowtest', MUST_EXIST);
        $done = $DB->record_exists('aiinteractivevideo_attempt', [
            'aiinteractivevideoid' => $instance->id,
            'userid' => $this->userid,
            'state' => manager::STATE_FINISHED,
            'mode' => manager::graded_mode($instance),
        ]);
        return $done ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Custom rules defined by this module.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionfinish'];
    }

    /**
     * Human readable descriptions of the active rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        return ['completionfinish' => get_string('completiondetail:finish', 'mod_aiinteractivevideo')];
    }

    /**
     * Sort order of completion rules on the activity page.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionfinish', 'completionusegrade', 'completionpassgrade'];
    }
}
