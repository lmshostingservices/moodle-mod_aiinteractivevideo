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
 * Saves the video position so an attempt can be resumed.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_progress extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'position' => new external_value(PARAM_FLOAT, 'Video position in seconds'),
            'elapsed' => new external_value(PARAM_INT, 'Active seconds since the last save', VALUE_DEFAULT, 0),
            'duration' => new external_value(PARAM_INT, 'Video length reported by the player', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Saves progress.
     *
     * @param int $attemptid
     * @param float $position
     * @param int $elapsed
     * @param int $duration
     * @return array
     */
    public static function execute(int $attemptid, float $position, int $elapsed = 0, int $duration = 0): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['attemptid' => $attemptid, 'position' => $position, 'elapsed' => $elapsed, 'duration' => $duration]
        );
        [$attempt, $instance] = helper::attempt($params['attemptid']);
        manager::progress($attempt, $instance, $params['position'], $params['elapsed'], $params['duration']);
        return ['status' => true];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Saved'),
        ]);
    }
}
