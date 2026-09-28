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

namespace mod_aiinteractivevideo\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for mod_aiinteractivevideo.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes stored personal data and data sent elsewhere.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('aiinteractivevideo_attempt', [
            'userid' => 'privacy:metadata:attempt:userid',
            'attempt' => 'privacy:metadata:attempt:attempt',
            'mode' => 'privacy:metadata:attempt:mode',
            'state' => 'privacy:metadata:attempt:state',
            'score' => 'privacy:metadata:attempt:score',
            'maxscore' => 'privacy:metadata:attempt:maxscore',
            'position' => 'privacy:metadata:attempt:position',
            'maxwatched' => 'privacy:metadata:attempt:maxwatched',
            'activetime' => 'privacy:metadata:attempt:activetime',
            'timestart' => 'privacy:metadata:attempt:timestart',
            'timefinish' => 'privacy:metadata:attempt:timefinish',
        ], 'privacy:metadata:attempt');
        $collection->add_database_table('aiinteractivevideo_response', [
            'attemptid' => 'privacy:metadata:response:attemptid',
            'sectionid' => 'privacy:metadata:response:sectionid',
            'tries' => 'privacy:metadata:response:tries',
            'solved' => 'privacy:metadata:response:solved',
            'points' => 'privacy:metadata:response:points',
            'hintsused' => 'privacy:metadata:response:hintsused',
            'revealed' => 'privacy:metadata:response:revealed',
            'rewatches' => 'privacy:metadata:response:rewatches',
            'timespent' => 'privacy:metadata:response:timespent',
            'response' => 'privacy:metadata:response:response',
        ], 'privacy:metadata:response');
        $collection->add_external_location_link('youtube', [
            'videoid' => 'privacy:metadata:youtube:videoid',
            'ipaddress' => 'privacy:metadata:youtube:ipaddress',
        ], 'privacy:metadata:youtube');
        $collection->add_subsystem_link('core_grades', [], 'privacy:metadata:core_grades');
        $collection->add_external_location_link('lmslabs', [
            'transcript' => 'privacy:metadata:lmslabs:transcript',
            'settings' => 'privacy:metadata:lmslabs:settings',
        ], 'privacy:metadata:lmslabs');
        return $collection;
    }

    /**
     * Contexts containing user data.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {aiinteractivevideo_attempt} a ON a.aiinteractivevideoid = cm.instance
                 WHERE a.userid = :userid";
        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, ['ctxlevel' => CONTEXT_MODULE, 'modname' => 'aiinteractivevideo', 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT a.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {aiinteractivevideo_attempt} a ON a.aiinteractivevideoid = cm.instance
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'aiinteractivevideo', 'cmid' => $context->instanceid]);
    }

    /**
     * Exports the user's attempts and per-interaction results.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('aiinteractivevideo', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $attempts = $DB->get_records(
                'aiinteractivevideo_attempt',
                ['aiinteractivevideoid' => $cm->instance, 'userid' => $userid],
                'mode, attempt'
            );
            if (!$attempts) {
                continue;
            }
            $data = [];
            foreach ($attempts as $a) {
                $responses = $DB->get_records_sql(
                    "SELECT r.*, s.title
                       FROM {aiinteractivevideo_response} r
                  LEFT JOIN {aiinteractivevideo_section} s ON s.id = r.sectionid
                      WHERE r.attemptid = :attemptid
                   ORDER BY s.sortorder",
                    ['attemptid' => $a->id]
                );
                $rows = [];
                foreach ($responses as $r) {
                    $rows[] = [
                        'interaction' => format_string((string)$r->title, true, ['context' => $context]),
                        'tries' => (int)$r->tries,
                        'solved' => transform::yesno($r->solved),
                        'points' => (int)$r->points,
                        'hintsused' => (int)$r->hintsused,
                        'revealed' => transform::yesno($r->revealed),
                        'rewatches' => (int)$r->rewatches,
                        'timespent' => (int)$r->timespent,
                        'response' => (string)$r->response,
                    ];
                }
                $data[] = [
                    'attempt' => (int)$a->attempt,
                    'mode' => $a->mode,
                    'state' => $a->state,
                    'score' => (int)$a->score,
                    'maxscore' => (int)$a->maxscore,
                    'position' => (float)$a->position,
                    'activetime' => (int)$a->activetime,
                    'timestart' => transform::datetime($a->timestart),
                    'timefinish' => $a->timefinish ? transform::datetime($a->timefinish) : '-',
                    'interactions' => $rows,
                ];
            }
            $contextdata = helper::get_context_data($context, $contextlist->get_user());
            helper::export_context_files($context, $contextlist->get_user());
            writer::with_context($context)->export_data([], $contextdata)
                ->export_data([get_string('attempts', 'mod_aiinteractivevideo')], (object)['attempts' => $data]);
        }
    }

    /**
     * Deletes all user data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('aiinteractivevideo', $context->instanceid);
        if ($cm) {
            self::delete_attempts((int)$cm->instance, null);
        }
    }

    /**
     * Deletes one user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('aiinteractivevideo', $context->instanceid);
            if ($cm) {
                self::delete_attempts((int)$cm->instance, [$userid]);
            }
        }
    }

    /**
     * Deletes data for the approved users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('aiinteractivevideo', $context->instanceid);
        if ($cm && $userlist->get_userids()) {
            self::delete_attempts((int)$cm->instance, array_map('intval', $userlist->get_userids()));
        }
    }

    /**
     * Deletes attempts and responses of an activity, optionally only for some users.
     *
     * @param int $instanceid
     * @param int[]|null $userids
     */
    private static function delete_attempts(int $instanceid, ?array $userids): void {
        global $DB;
        $params = ['id' => $instanceid];
        $where = 'aiinteractivevideoid = :id';
        if ($userids !== null) {
            [$insql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
            $where .= " AND userid $insql";
            $params += $uparams;
        }
        $attemptids = $DB->get_fieldset_select('aiinteractivevideo_attempt', 'id', $where, $params);
        if ($attemptids) {
            [$insql, $aparams] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'a');
            $DB->delete_records_select('aiinteractivevideo_response', "attemptid $insql", $aparams);
            $DB->delete_records_select('aiinteractivevideo_attempt', "id $insql", $aparams);
        }
    }
}
