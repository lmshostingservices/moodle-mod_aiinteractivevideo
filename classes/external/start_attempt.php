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

namespace mod_aiinteractivevideo\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_multiple_structure;
use mod_aiinteractivevideo\local\manager;

/**
 * Starts or resumes a learn or test attempt.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class start_attempt extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'mode' => new external_value(PARAM_ALPHA, 'learn or test'),
        ]);
    }

    /**
     * Starts or resumes the attempt.
     *
     * @param int $cmid
     * @param string $mode
     * @return array
     */
    public static function execute(int $cmid, string $mode): array {
        global $DB, $USER;
        ['cmid' => $cmid, 'mode' => $mode] = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'mode' => $mode]
        );
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'aiinteractivevideo');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        helper::require_can_attempt($context);
        $instance = $DB->get_record('aiinteractivevideo', ['id' => $cm->instance], '*', MUST_EXIST);
        $attempt = manager::start_attempt($instance, (int)$USER->id, $mode, $context);
        $data = manager::attempt_data($instance, $attempt);
        $data['attemptsleft'] = manager::attempts_left($instance, (int)$USER->id);
        return $data;
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'attempt' => new external_value(PARAM_INT, 'Attempt number'),
            'mode' => new external_value(PARAM_ALPHA, 'Mode'),
            'position' => new external_value(PARAM_FLOAT, 'Saved video position'),
            'maxwatched' => new external_value(PARAM_FLOAT, 'Furthest point watched'),
            'activetime' => new external_value(PARAM_INT, 'Active seconds so far'),
            'score' => new external_value(PARAM_INT, 'Points so far'),
            'maxscore' => new external_value(PARAM_INT, 'Maximum points'),
            'attemptsleft' => new external_value(PARAM_INT, 'Graded attempts left, -1 unlimited'),
            'sections' => new external_multiple_structure(helper::section_structure(), 'Sections'),
        ]);
    }
}
