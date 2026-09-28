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
use mod_aiinteractivevideo\local\manager;

/**
 * Marks the learner response to one interaction.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_response extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'sectionid' => new external_value(PARAM_INT, 'Section id'),
            'response' => helper::pairs_parameter('Response'),
            'timespent' => new external_value(PARAM_INT, 'Seconds spent on this try', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Marks the response.
     *
     * @param int $attemptid
     * @param int $sectionid
     * @param array $response
     * @param int $timespent
     * @return array
     */
    public static function execute(int $attemptid, int $sectionid, array $response = [], int $timespent = 0): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['attemptid' => $attemptid, 'sectionid' => $sectionid, 'response' => $response, 'timespent' => $timespent]
        );
        [$attempt, $instance] = helper::attempt($params['attemptid']);
        $section = manager::attempt_section($attempt, $params['sectionid']);
        return manager::submit($attempt, $instance, $section, $params['response'], $params['timespent']);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'correct' => new external_value(PARAM_BOOL, 'Whether the response is correct'),
            'points' => new external_value(PARAM_INT, 'Points for this interaction'),
            'tries' => new external_value(PARAM_INT, 'Tries used'),
            'triesleft' => new external_value(PARAM_INT, 'Tries left, -1 unlimited'),
            'status' => new external_value(PARAM_ALPHA, 'Status'),
            'score' => new external_value(PARAM_INT, 'Attempt points'),
            'results' => helper::pairs_structure('Per item results'),
            'missing' => new external_value(PARAM_INT, 'Correct choices not selected'),
            'feedback' => new external_value(PARAM_TEXT, 'Feedback'),
            'rewatchfrom' => new external_value(PARAM_FLOAT, 'Section start for the rewatch'),
        ]);
    }
}
