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
 * Creates the interactions with LMS Labs AI (teachers).
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'action' => new external_value(
                PARAM_ALPHA,
                'continue: finish the requested generation; retry: try a failed generation again; ' .
                'regenerate: a new generation (charged)',
                VALUE_DEFAULT,
                'continue'
            ),
        ]);
    }

    /**
     * Runs or requests a generation.
     *
     * @param int $cmid
     * @param string $action continue, retry or regenerate
     * @return array
     */
    public static function execute(int $cmid, string $action = 'continue'): array {
        global $DB, $USER;
        ['cmid' => $cmid, 'action' => $action] = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'action' => $action]
        );
        if (!in_array($action, ['continue', 'retry', 'regenerate'], true)) {
            throw new \invalid_parameter_exception('Unsupported action');
        }
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'aiinteractivevideo');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/aiinteractivevideo:manage', $context);
        $instance = $DB->get_record('aiinteractivevideo', ['id' => $cm->instance], '*', MUST_EXIST);
        \core_php_time_limit::raise(300);
        if ($action !== 'continue') {
            manager::request_generation($instance, $context, (int)$USER->id, $action === 'retry');
        }
        return ['count' => manager::run_ai($instance)];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Number of interactions in the activity'),
        ]);
    }
}
