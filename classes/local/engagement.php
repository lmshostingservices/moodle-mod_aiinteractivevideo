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

namespace mod_aiinteractivevideo\local;

use stdClass;

/**
 * Video engagement: which seconds of the video each learner really watched, and per-learner results.
 *
 * Watched time is kept as a list of second ranges ("0-45,50-120", start inclusive, end exclusive), so
 * replays and rewinds never count twice and skipping ahead never counts as watching.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engagement {
    /** Share of the video (percent) that counts as watching the whole video. */
    public const WHOLE_PERCENT = 95;

    /** Most ranges kept per attempt (the closest neighbours are joined beyond this). */
    public const MAX_RANGES = 400;

    /** Per-interaction result: no answer yet. */
    public const RESULT_NONE = 'none';
    /** Per-interaction result: right on the first try. */
    public const RESULT_FIRST = 'first';
    /** Per-interaction result: right after one or more wrong tries. */
    public const RESULT_LATER = 'later';
    /** Per-interaction result: wrong, answer shown or try limit reached. */
    public const RESULT_WRONG = 'wrong';

    /**
     * Parses a range string into merged [start, end] pairs.
     *
     * @param string|null $text "0-45,50-120"
     * @param int $limit largest second allowed (0 for no limit)
     * @return array list of [int start, int end]
     */
    public static function parse(?string $text, int $limit = 0): array {
        $out = [];
        foreach (explode(',', (string)$text) as $part) {
            if (!preg_match('/^\s*(\d{1,6})\s*-\s*(\d{1,6})\s*$/', $part, $m)) {
                continue;
            }
            $s = (int)$m[1];
            $e = (int)$m[2];
            if ($limit > 0) {
                $s = min($s, $limit);
                $e = min($e, $limit);
            }
            if ($e > $s) {
                $out[] = [$s, $e];
            }
        }
        return self::normalise($out);
    }

    /**
     * Sorts and merges overlapping or touching ranges.
     *
     * @param array $ranges list of [start, end]
     * @return array
     */
    public static function normalise(array $ranges): array {
        usort($ranges, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $out = [];
        foreach ($ranges as [$s, $e]) {
            $n = count($out);
            if ($n && $s <= $out[$n - 1][1]) {
                $out[$n - 1][1] = max($out[$n - 1][1], $e);
            } else {
                $out[] = [$s, $e];
            }
        }
        while (count($out) > self::MAX_RANGES) {
            // Join the two neighbours with the smallest gap (keeps the stored text bounded).
            $best = 1;
            for ($i = 2; $i < count($out); $i++) {
                if ($out[$i][0] - $out[$i - 1][1] < $out[$best][0] - $out[$best - 1][1]) {
                    $best = $i;
                }
            }
            $out[$best - 1][1] = $out[$best][1];
            array_splice($out, $best, 1);
        }
        return $out;
    }

    /**
     * Formats ranges as text.
     *
     * @param array $ranges
     * @return string
     */
    public static function format(array $ranges): string {
        return implode(',', array_map(fn($r) => $r[0] . '-' . $r[1], $ranges));
    }

    /**
     * Total seconds covered.
     *
     * @param array $ranges
     * @return int
     */
    public static function seconds(array $ranges): int {
        return array_sum(array_map(fn($r) => $r[1] - $r[0], $ranges));
    }

    /**
     * Adds newly watched ranges to an attempt's stored ranges.
     *
     * @param string|null $stored current text
     * @param string $added new ranges from the player
     * @param int $duration video length in seconds (0 if unknown)
     * @return array [string text, int seconds]
     */
    public static function merge(?string $stored, string $added, int $duration): array {
        $limit = $duration > 0 ? $duration : 86400;
        $ranges = self::normalise(array_merge(self::parse($stored, $limit), self::parse($added, $limit)));
        return [self::format($ranges), self::seconds($ranges)];
    }

    /**
     * Share of the video watched, 0-100.
     *
     * @param int $seconds
     * @param int $duration
     * @return int
     */
    public static function percent(int $seconds, int $duration): int {
        if ($duration <= 0) {
            return 0;
        }
        return (int)min(100, round($seconds / $duration * 100));
    }

    /**
     * Result of one interaction in an attempt.
     *
     * @param stdClass|null $r response row
     * @return string one of the RESULT_ constants
     */
    public static function result(?stdClass $r): string {
        if (!$r || ((int)$r->tries === 0 && empty($r->revealed) && empty($r->skipped))) {
            return self::RESULT_NONE;
        }
        if (!empty($r->revealed) || !empty($r->skipped) || empty($r->solved)) {
            return self::RESULT_WRONG;
        }
        return (int)$r->tries <= 1 ? self::RESULT_FIRST : self::RESULT_LATER;
    }

    /**
     * Video length used for the percentages.
     *
     * @param stdClass $instance
     * @param array $sections
     * @return int
     */
    public static function duration(stdClass $instance, array $sections): int {
        $d = (int)$instance->videoduration;
        if ($d <= 0 && $sections) {
            $d = (int)ceil((float)end($sections)->endtime);
        }
        return $d;
    }

    /**
     * Learners to report on: everyone who can attempt the activity (active enrolments), plus anyone with attempts.
     *
     * @param \context_module $context
     * @param stdClass $instance
     * @param int $groupid 0 for all
     * @return array userid => user record (name fields and email)
     */
    public static function learners(\context_module $context, stdClass $instance, int $groupid = 0): array {
        global $DB;
        $fields = 'u.id, u.email, ' . \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $users = get_enrolled_users($context, 'mod/aiinteractivevideo:attempt', $groupid, $fields, null, 0, 0, true);
        $params = ['id' => $instance->id];
        $groupsql = '';
        if ($groupid) {
            $groupsql = ' AND u.id IN (SELECT gm.userid FROM {groups_members} gm WHERE gm.groupid = :gid)';
            $params['gid'] = $groupid;
        }
        $extra = $DB->get_records_sql(
            "SELECT DISTINCT $fields
               FROM {user} u
               JOIN {aiinteractivevideo_attempt} a ON a.userid = u.id
              WHERE a.aiinteractivevideoid = :id AND u.deleted = 0 $groupsql",
            $params
        );
        foreach ($extra as $u) {
            if (!isset($users[$u->id])) {
                $users[$u->id] = $u;
            }
        }
        uasort($users, fn($a, $b) => [$a->lastname, $a->firstname] <=> [$b->lastname, $b->firstname]);
        return $users;
    }

    /**
     * One engagement row per learner for a mode.
     *
     * The interaction results come from the learner's latest finished attempt (or the latest attempt when
     * none is finished). Watched time is everything the learner watched across their attempts in the mode.
     *
     * @param stdClass $instance
     * @param array $sections ordered section records
     * @param array $users from learners()
     * @param string $mode learn or test
     * @return array userid => stdClass row
     */
    public static function rows(stdClass $instance, array $sections, array $users, string $mode): array {
        global $DB;
        $duration = self::duration($instance, $sections);
        $rows = [];
        foreach ($users as $u) {
            $rows[$u->id] = (object)[
                'user' => $u,
                'status' => 'notstarted',
                'attempts' => 0,
                'watchedsecs' => 0,
                'watchedpct' => 0,
                'wholevideo' => false,
                'answered' => 0,
                'total' => count($sections),
                'alldone' => false,
                'firsttry' => 0,
                'results' => [],
                'wrong' => [],
                'notanswered' => [],
                'bestpct' => null,
                'activetime' => 0,
                'rewatches' => 0,
                'lastactive' => 0,
                'latestid' => 0,
            ];
        }
        if (!$rows) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED, 'u');
        $params += ['id' => $instance->id, 'mode' => $mode];
        $attempts = $DB->get_records_select(
            'aiinteractivevideo_attempt',
            "aiinteractivevideoid = :id AND mode = :mode AND userid $insql",
            $params,
            'userid, attempt',
            'id, userid, attempt, state, score, maxscore, activetime, watchedranges, maxwatched, timestart, timemodified'
        );
        $ranges = [];
        $latest = [];
        foreach ($attempts as $a) {
            $row = $rows[$a->userid];
            $row->attempts++;
            $row->activetime += (int)$a->activetime;
            $row->lastactive = max($row->lastactive, (int)$a->timemodified);
            $ranges[$a->userid] = array_merge($ranges[$a->userid] ?? [], self::parse($a->watchedranges, $duration));
            if ($a->state === manager::STATE_FINISHED) {
                $row->status = 'finished';
                $pct = $a->maxscore ? round($a->score / $a->maxscore * 100) : 0;
                $row->bestpct = $row->bestpct === null ? $pct : max($row->bestpct, $pct);
            } else if ($row->status !== 'finished') {
                $row->status = 'inprogress';
            }
            $cur = $latest[$a->userid] ?? null;
            $finished = $a->state === manager::STATE_FINISHED;
            if (
                !$cur || ($finished && $cur->state !== manager::STATE_FINISHED) ||
                    ($finished === ($cur->state === manager::STATE_FINISHED) && $a->attempt > $cur->attempt)
            ) {
                $latest[$a->userid] = $a;
            }
        }
        $responses = [];
        if ($latest) {
            [$asql, $aparams] = $DB->get_in_or_equal(array_map(fn($a) => $a->id, $latest), SQL_PARAMS_NAMED, 'a');
            foreach ($DB->get_records_select('aiinteractivevideo_response', "attemptid $asql", $aparams) as $r) {
                $responses[$r->attemptid][$r->sectionid] = $r;
            }
        }
        foreach ($rows as $uid => $row) {
            $merged = self::normalise($ranges[$uid] ?? []);
            $row->watchedsecs = self::seconds($merged);
            $row->watchedpct = self::percent($row->watchedsecs, $duration);
            $row->wholevideo = $row->watchedpct >= self::WHOLE_PERCENT;
            $a = $latest[$uid] ?? null;
            $row->latestid = $a ? (int)$a->id : 0;
            foreach (array_values($sections) as $i => $section) {
                $r = $a ? ($responses[$a->id][$section->id] ?? null) : null;
                $res = self::result($r);
                $row->results[$section->id] = (object)['result' => $res, 'tries' => $r ? (int)$r->tries : 0, 'number' => $i + 1];
                if ($r) {
                    $row->rewatches += (int)$r->rewatches;
                }
                if ($res === self::RESULT_NONE) {
                    $row->notanswered[] = $i + 1;
                    continue;
                }
                $row->answered++;
                if ($res === self::RESULT_FIRST) {
                    $row->firsttry++;
                } else {
                    $row->wrong[] = $i + 1;
                }
            }
            $row->alldone = $row->total > 0 && $row->answered === $row->total;
        }
        return $rows;
    }

    /**
     * Class totals from the learner rows.
     *
     * @param array $rows from rows()
     * @return stdClass
     */
    public static function totals(array $rows): stdClass {
        $t = (object)['learners' => count($rows), 'notstarted' => 0, 'inprogress' => 0, 'finished' => 0,
            'wholevideo' => 0, 'alldone' => 0, 'avgwatched' => 0];
        foreach ($rows as $r) {
            $t->{$r->status}++;
            $t->wholevideo += $r->wholevideo ? 1 : 0;
            $t->alldone += $r->alldone ? 1 : 0;
        }
        $started = $t->learners - $t->notstarted;
        $t->avgwatched = $started ? (int)round(array_sum(array_map(
            fn($r) => $r->status === 'notstarted' ? 0 : $r->watchedpct,
            $rows
        )) / $started) : 0;
        return $t;
    }

    /**
     * Interactions the class got wrong most, from the learners' latest attempts.
     *
     * @param array $sections ordered section records
     * @param array $rows from rows()
     * @return array sectionid => stdClass (number, section, answered, wrong, wrongpct, names) sorted by most wrong
     */
    public static function most_missed(array $sections, array $rows): array {
        $out = [];
        foreach (array_values($sections) as $i => $s) {
            $out[$s->id] = (object)['number' => $i + 1, 'section' => $s, 'answered' => 0, 'wrong' => 0,
                'wrongpct' => 0, 'names' => []];
        }
        foreach ($rows as $row) {
            foreach ($row->results as $sid => $res) {
                if (!isset($out[$sid]) || $res->result === self::RESULT_NONE) {
                    continue;
                }
                $out[$sid]->answered++;
                if ($res->result !== self::RESULT_FIRST) {
                    $out[$sid]->wrong++;
                    $out[$sid]->names[] = fullname($row->user);
                }
            }
        }
        foreach ($out as $o) {
            $o->wrongpct = $o->answered ? (int)round($o->wrong / $o->answered * 100) : 0;
        }
        uasort($out, fn($a, $b) => [$b->wrong, $b->wrongpct, $a->number] <=> [$a->wrong, $a->wrongpct, $b->number]);
        return $out;
    }
}
