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

use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_aiinteractivevideo\local\manager;

/**
 * Shared checks and return structures for the learner web services.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Loads the user's open attempt and checks context and capability.
     *
     * @param int $attemptid
     * @param bool $mustbeopen
     * @return array [attempt, instance, cm, course, context]
     */
    public static function attempt(int $attemptid, bool $mustbeopen = true): array {
        global $USER;
        $loaded = manager::own_attempt($attemptid, (int)$USER->id);
        $context = $loaded[4];
        \core_external\external_api::validate_context($context);
        self::require_can_attempt($context);
        if ($mustbeopen) {
            manager::require_open($loaded[0]);
        }
        return $loaded;
    }

    /**
     * Learners need :attempt; teachers with :manage may try the activity too.
     *
     * @param \context_module $context
     */
    public static function require_can_attempt(\context_module $context): void {
        require_capability('mod/aiinteractivevideo:view', $context);
        if (!has_capability('mod/aiinteractivevideo:manage', $context)) {
            require_capability('mod/aiinteractivevideo:attempt', $context);
        }
        if (isguestuser()) {
            throw new \moodle_exception('noguestattempts', 'mod_aiinteractivevideo');
        }
    }

    /**
     * Key/value list parameter.
     *
     * @param string $desc
     * @return external_multiple_structure
     */
    public static function pairs_parameter(string $desc): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'Key token'),
                'value' => new external_value(PARAM_ALPHANUMEXT, 'Value token'),
            ]),
            $desc,
            VALUE_DEFAULT,
            []
        );
    }

    /**
     * Key/value result list.
     *
     * @param string $desc
     * @return external_multiple_structure
     */
    public static function pairs_structure(string $desc): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'Key token'),
                'value' => new external_value(PARAM_ALPHANUMEXT, 'Value token'),
                'text' => new external_value(PARAM_TEXT, 'Text, e.g. a correction', VALUE_DEFAULT, ''),
                'correct' => new external_value(PARAM_INT, '1 if correct', VALUE_DEFAULT, 0),
            ]),
            $desc
        );
    }

    /**
     * One learner section with its interaction payload.
     *
     * @return external_single_structure
     */
    public static function section_structure(): external_single_structure {
        $tokentext = new external_single_structure([
            'token' => new external_value(PARAM_ALPHANUMEXT, 'Token'),
            'text' => new external_value(PARAM_TEXT, 'Text'),
        ]);
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Section id'),
            'index' => new external_value(PARAM_INT, 'Position'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'start' => new external_value(PARAM_FLOAT, 'Start in seconds'),
            'end' => new external_value(PARAM_FLOAT, 'Pause time in seconds'),
            'type' => new external_value(PARAM_ALPHA, 'Interaction type'),
            'status' => new external_value(PARAM_ALPHA, 'pending, solved, revealed or skipped'),
            'points' => new external_value(PARAM_INT, 'Points earned'),
            'tries' => new external_value(PARAM_INT, 'Tries used'),
            'hashint' => new external_value(PARAM_BOOL, 'Whether a hint is available'),
            'prompt' => new external_value(PARAM_TEXT, 'Instruction'),
            'selectcount' => new external_value(PARAM_INT, 'Number of choices to select'),
            'wordlengths' => new external_value(PARAM_SEQUENCE, 'Word lengths for unscramble'),
            'items' => new external_multiple_structure($tokentext, 'Items'),
            'groups' => new external_multiple_structure($tokentext, 'Groups or targets'),
            'segments' => new external_multiple_structure(new external_single_structure([
                'text' => new external_value(PARAM_TEXT, 'Text'),
                'token' => new external_value(PARAM_ALPHANUMEXT, 'Token'),
                'blank' => new external_value(PARAM_INT, '1 for a gap'),
            ]), 'Passage segments'),
        ]);
    }

    /**
     * Summary returned when an attempt finishes.
     *
     * @return external_single_structure
     */
    public static function summary_structure(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'mode' => new external_value(PARAM_ALPHA, 'Mode'),
            'score' => new external_value(PARAM_INT, 'Points'),
            'maxscore' => new external_value(PARAM_INT, 'Maximum points'),
            'percent' => new external_value(PARAM_FLOAT, 'Percentage'),
            'duration' => new external_value(PARAM_INT, 'Active seconds'),
            'firsttry' => new external_value(PARAM_INT, 'Correct first time'),
            'hints' => new external_value(PARAM_INT, 'Hints used'),
            'reveals' => new external_value(PARAM_INT, 'Answers shown'),
            'rewatches' => new external_value(PARAM_INT, 'Sections rewatched'),
            'graded' => new external_value(PARAM_BOOL, 'Whether this attempt is graded'),
            'attemptsleft' => new external_value(PARAM_INT, 'Graded attempts left, -1 unlimited'),
            'sections' => new external_multiple_structure(new external_single_structure([
                'title' => new external_value(PARAM_TEXT, 'Title'),
                'type' => new external_value(PARAM_ALPHA, 'Type'),
                'status' => new external_value(PARAM_ALPHA, 'Status'),
                'points' => new external_value(PARAM_INT, 'Points'),
                'tries' => new external_value(PARAM_INT, 'Tries'),
                'hints' => new external_value(PARAM_INT, 'Hints used'),
                'timespent' => new external_value(PARAM_INT, 'Seconds'),
                'start' => new external_value(PARAM_FLOAT, 'Start'),
            ]), 'Per interaction'),
            'leaderboard' => new external_multiple_structure(new external_single_structure([
                'rank' => new external_value(PARAM_INT, 'Rank'),
                'name' => new external_value(PARAM_TEXT, 'Display name'),
                'score' => new external_value(PARAM_INT, 'Points'),
                'maxscore' => new external_value(PARAM_INT, 'Maximum points'),
                'duration' => new external_value(PARAM_INT, 'Seconds'),
                'me' => new external_value(PARAM_BOOL, 'Current user'),
            ]), 'Leaderboard'),
        ]);
    }
}
