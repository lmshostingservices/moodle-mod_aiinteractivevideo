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
 * External services for mod_aiinteractivevideo.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_aiinteractivevideo_start_attempt' => [
        'classname' => 'mod_aiinteractivevideo\external\start_attempt',
        'description' => 'Starts or resumes a learn or test attempt.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aiinteractivevideo:view',
    ],
    'mod_aiinteractivevideo_submit_response' => [
        'classname' => 'mod_aiinteractivevideo\external\submit_response',
        'description' => 'Marks the learner response to one interaction.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aiinteractivevideo:view',
    ],
    'mod_aiinteractivevideo_get_hint' => [
        'classname' => 'mod_aiinteractivevideo\external\get_hint',
        'description' => 'Returns a hint for an interaction (learn mode).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aiinteractivevideo:view',
    ],
    'mod_aiinteractivevideo_reveal_answer' => [
        'classname' => 'mod_aiinteractivevideo\external\reveal_answer',
        'description' => 'Shows the answer to an interaction (learn mode).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aiinteractivevideo:view',
    ],
    'mod_aiinteractivevideo_record_rewatch' => [
        'classname' => 'mod_aiinteractivevideo\external\record_rewatch',
        'description' => 'Records that a section was rewatched, and saves the video position.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aiinteractivevideo:view',
    ],
    'mod_aiinteractivevideo_save_progress' => [
        'classname' => 'mod_aiinteractivevideo\external\save_progress',
        'description' => 'Saves the video position so an attempt can be resumed.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aiinteractivevideo:view',
    ],
    'mod_aiinteractivevideo_finish_attempt' => [
        'classname' => 'mod_aiinteractivevideo\external\finish_attempt',
        'description' => 'Finishes an attempt and returns the results and leaderboard.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aiinteractivevideo:view',
    ],
    'mod_aiinteractivevideo_generate' => [
        'classname' => 'mod_aiinteractivevideo\external\generate',
        'description' => 'Creates the sections and interactions with LMS Labs AI.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/aiinteractivevideo:manage',
    ],
];
