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
 * Shows the answer to an interaction (learn mode).
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reveal_answer extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'sectionid' => new external_value(PARAM_INT, 'Section id'),
        ]);
    }

    /**
     * Reveals the answer.
     *
     * @param int $attemptid
     * @param int $sectionid
     * @return array
     */
    public static function execute(int $attemptid, int $sectionid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid, 'sectionid' => $sectionid]);
        [$attempt] = helper::attempt($params['attemptid']);
        $section = manager::attempt_section($attempt, $params['sectionid']);
        return manager::reveal($attempt, $section);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'solution' => helper::pairs_structure('Answer'),
            'feedback' => new external_value(PARAM_TEXT, 'Explanation'),
            'score' => new external_value(PARAM_INT, 'Attempt points'),
        ]);
    }
}
