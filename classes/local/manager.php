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

use context_module;
use stdClass;

/**
 * Attempts, marking, grading, leaderboard and reporting logic.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var string Attempt in progress. */
    public const STATE_INPROGRESS = 'inprogress';
    /** @var string Attempt finished. */
    public const STATE_FINISHED = 'finished';

    /** @var int Generation finished. */
    public const GEN_READY = 0;
    /** @var int AI generation queued. */
    public const GEN_PENDING = 1;
    /** @var int AI generation failed (built-in activities are in place). */
    public const GEN_FAILED = 2;

    /**
     * Sections of an activity, in order.
     *
     * @param int $instanceid
     * @return stdClass[] keyed by id
     */
    public static function get_sections(int $instanceid): array {
        global $DB;
        return $DB->get_records('aiinteractivevideo_section', ['aiinteractivevideoid' => $instanceid], 'sortorder ASC, id ASC');
    }

    /**
     * The mode whose attempts are graded: Test when enabled, otherwise Learn.
     *
     * @param stdClass $instance
     * @return string
     */
    public static function graded_mode(stdClass $instance): string {
        return !empty($instance->allowtest) ? 'test' : 'learn';
    }

    /**
     * Starts the automatic creation of the interactions after the settings form was saved.
     *
     * @param int $instanceid
     * @param context_module $context
     * @param int $userid
     */
    public static function build_after_save(int $instanceid, context_module $context, int $userid): void {
        global $DB;
        $instance = $DB->get_record('aiinteractivevideo', ['id' => $instanceid], '*', MUST_EXIST);
        try {
            self::request_generation($instance, $context, $userid);
        } catch (lmslabs_exception $e) {
            if ($e->reason === lmslabs_exception::UNRESOLVED) {
                // The earlier request stays as it is; the teacher is told to finish it with "Try again" first.
                set_config(
                    'generror_' . $instance->id,
                    json_encode(['message' => $e->getMessage(), 'buyurl' => '']),
                    'mod_aiinteractivevideo'
                );
            }
            // Otherwise already recorded on the activity: the teacher sees the reason and can try again.
            return;
        }
    }

    /**
     * Requests one LMS Labs AI generation: status "building" and a background task (the teacher's page also runs it
     * straight away).
     *
     * A new generation gets a new idempotency key (a UUID), and the exact request body is stored with it before
     * anything is sent. "Try again" after a failure keeps the unfinished key and body when there are any, so a
     * request that LMS Labs may already have charged is repeated exactly and never charged twice. While such a request
     * is unfinished, a new generation is refused: a new key is never made automatically after an unknown outcome.
     *
     * @param stdClass $instance
     * @param context_module $context
     * @param int $userid
     * @param bool $retry true for "Try again" after a failure
     * @throws lmslabs_exception when LMS Labs is not set up, or UNRESOLVED when an earlier request is unfinished
     */
    public static function request_generation(
        stdClass $instance, context_module $context, int $userid,
        bool $retry = false
    ): void {
        global $DB;
        if (!lmslabs::configured()) {
            $e = new lmslabs_exception(lmslabs_exception::NOTCONFIGURED);
            self::fail_generation($instance, $e);
            throw $e;
        }
        if (!$retry && self::generation_key($instance->id) !== '') {
            throw new lmslabs_exception(lmslabs_exception::UNRESOLVED);
        }
        if (self::generation_key($instance->id) === '') {
            // Stored before the first call: the body first, then the key that makes it the pending request.
            $key = lmslabs::new_key();
            self::store_request($context, ai_generator::request_body($instance, $key));
            set_config('genkey_' . $instance->id, $key, 'mod_aiinteractivevideo');
        }
        unset_config('generror_' . $instance->id, 'mod_aiinteractivevideo');
        $DB->set_field('aiinteractivevideo', 'genstatus', self::GEN_PENDING, ['id' => $instance->id]);
        $DB->set_field('aiinteractivevideo', 'timemodified', time(), ['id' => $instance->id]);
        $task = new \mod_aiinteractivevideo\task\generate_interactions();
        $task->set_custom_data(['instanceid' => $instance->id, 'contextid' => $context->id]);
        $task->set_userid($userid);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * The idempotency key of the generation request that has not finished yet, if any.
     *
     * @param int $instanceid
     * @return string
     */
    public static function generation_key(int $instanceid): string {
        return (string)get_config('mod_aiinteractivevideo', 'genkey_' . $instanceid);
    }

    /**
     * Why the last generation failed, for the teacher.
     *
     * @param int $instanceid
     * @return array|null ['message' => text, 'buyurl' => link or '']
     */
    public static function generation_error(int $instanceid): ?array {
        $stored = get_config('mod_aiinteractivevideo', 'generror_' . $instanceid);
        $data = $stored ? json_decode($stored, true) : null;
        if (!is_array($data)) {
            return null;
        }
        return ['message' => (string)($data['message'] ?? ''), 'buyurl' => (string)($data['buyurl'] ?? '')];
    }

    /**
     * Runs the requested LMS Labs AI generation (from the teacher's page or the background task).
     *
     * A lock stops two runs at once. When nothing is waiting (the other run already finished), nothing is sent.
     *
     * @param stdClass $instance
     * @return int number of interactions now in the activity
     * @throws lmslabs_exception
     */
    public static function run_ai(stdClass $instance): int {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('mod_aiinteractivevideo')->get_lock('generate_' . $instance->id, 30);
        if (!$lock) {
            throw new lmslabs_exception(lmslabs_exception::PROCESSING);
        }
        try {
            $key = self::generation_key($instance->id);
            if ($key === '') {
                return $DB->count_records('aiinteractivevideo_section', ['aiinteractivevideoid' => $instance->id]);
            }
            $instance = $DB->get_record('aiinteractivevideo', ['id' => $instance->id], '*', MUST_EXIST);
            try {
                $context = \context_module::instance(
                    get_coursemodule_from_instance('aiinteractivevideo', $instance->id, 0, false, MUST_EXIST)->id
                );
                $body = self::stored_request($context, $key);
                if ($body === null) {
                    // A request made by an earlier release: store its body once, then always send the same bytes.
                    $body = ai_generator::request_body($instance, $key);
                    self::store_request($context, $body);
                }
                $sections = ai_generator::generate($instance, $body);
            } catch (lmslabs_exception $e) {
                self::fail_generation($instance, $e);
                throw $e;
            } catch (\Throwable $e) {
                // Unexpected errors: keep the key, because LMS Labs may already have charged this request.
                debugging('AI interactive video generation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $wrapped = new lmslabs_exception(lmslabs_exception::UNREACHABLE, '', $e->getMessage());
                self::fail_generation($instance, $wrapped);
                throw $wrapped;
            }
            self::replace_sections($instance, $sections);
            self::clear_request($instance->id);
            unset_config('generror_' . $instance->id, 'mod_aiinteractivevideo');
            $DB->set_field('aiinteractivevideo', 'genstatus', self::GEN_READY, ['id' => $instance->id]);
            return count($sections);
        } finally {
            $lock->release();
        }
    }

    /**
     * Records a failed generation. Existing interactions stay in place.
     *
     * @param stdClass $instance
     * @param lmslabs_exception $e
     */
    private static function fail_generation(stdClass $instance, lmslabs_exception $e): void {
        global $DB;
        if (!$e->may_be_charged()) {
            // A definite answer that nothing was charged: the next try is a new request.
            self::clear_request($instance->id);
        }
        set_config(
            'generror_' . $instance->id,
            json_encode(['message' => $e->getMessage(), 'buyurl' => $e->buyurl]),
            'mod_aiinteractivevideo'
        );
        $DB->set_field('aiinteractivevideo', 'genstatus', self::GEN_FAILED, ['id' => $instance->id]);
    }

    /**
     * Removes the stored generation state of a deleted activity.
     *
     * @param int $instanceid
     */
    public static function forget_generation(int $instanceid): void {
        self::clear_request($instanceid);
        unset_config('generror_' . $instanceid, 'mod_aiinteractivevideo');
    }

    /**
     * Stores the exact request body of the pending generation (a private file: never served to browsers).
     *
     * @param \context $context
     * @param string $body
     */
    private static function store_request(\context $context, string $body): void {
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_aiinteractivevideo', 'genrequest');
        $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_aiinteractivevideo', 'filearea' => 'genrequest',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'request.json',
        ], $body);
    }

    /**
     * The stored request body for this idempotency key, if there is one.
     *
     * @param \context $context
     * @param string $key
     * @return string|null
     */
    public static function stored_request(\context $context, string $key): ?string {
        $file = get_file_storage()->get_file($context->id, 'mod_aiinteractivevideo', 'genrequest', 0, '/', 'request.json');
        if (!$file) {
            return null;
        }
        $body = $file->get_content();
        $data = json_decode($body, true);
        return is_array($data) && ($data['idempotencyKey'] ?? '') === $key ? $body : null;
    }

    /**
     * Ends the pending request: removes its key and stored body.
     *
     * @param int $instanceid
     */
    private static function clear_request(int $instanceid): void {
        unset_config('genkey_' . $instanceid, 'mod_aiinteractivevideo');
        $cm = get_coursemodule_from_instance('aiinteractivevideo', $instanceid, 0, false, IGNORE_MISSING);
        if ($cm) {
            $context = \context_module::instance($cm->id, IGNORE_MISSING);
            if ($context) {
                get_file_storage()->delete_area_files($context->id, 'mod_aiinteractivevideo', 'genrequest');
            }
        }
    }

    /**
     * Replaces all sections, deletes attempts made against the old ones and syncs the grade.
     *
     * @param stdClass $instance
     * @param array $sections section records from a generator
     */
    public static function replace_sections(stdClass $instance, array $sections): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        self::delete_attempts($instance);
        $DB->delete_records('aiinteractivevideo_section', ['aiinteractivevideoid' => $instance->id]);
        $now = time();
        foreach (array_values($sections) as $i => $section) {
            $record = clone $section;
            $record->aiinteractivevideoid = $instance->id;
            $record->sortorder = $i + 1;
            $record->timecreated = $now;
            $record->timemodified = $now;
            unset($record->id);
            $DB->insert_record('aiinteractivevideo_section', $record);
        }
        $transaction->allow_commit();
        self::sync_grade($instance);
    }

    /**
     * Deletes every attempt of an activity.
     *
     * @param stdClass $instance
     */
    public static function delete_attempts(stdClass $instance): void {
        global $DB;
        $attemptids = $DB->get_fieldset_select(
            'aiinteractivevideo_attempt',
            'id',
            'aiinteractivevideoid = :id',
            ['id' => $instance->id]
        );
        if ($attemptids) {
            [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('aiinteractivevideo_response', "attemptid $insql", $params);
            $DB->delete_records('aiinteractivevideo_attempt', ['aiinteractivevideoid' => $instance->id]);
        }
    }

    /**
     * Sets the maximum grade to the number of interactions (1 point each) and pushes grades.
     *
     * @param stdClass $instance
     */
    public static function sync_grade(stdClass $instance): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/aiinteractivevideo/lib.php');
        $count = $DB->count_records('aiinteractivevideo_section', ['aiinteractivevideoid' => $instance->id]);
        $DB->set_field('aiinteractivevideo', 'grade', $count, ['id' => $instance->id]);
        $fresh = $DB->get_record('aiinteractivevideo', ['id' => $instance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('aiinteractivevideo', $fresh->id, $fresh->course, false, IGNORE_MISSING);
        $fresh->cmidnumber = $cm ? $cm->idnumber : '';
        aiinteractivevideo_grade_item_update($fresh);
        aiinteractivevideo_update_grades($fresh, 0, false);
    }

    /**
     * Number of graded attempts still available (-1 = unlimited).
     *
     * @param stdClass $instance
     * @param int $userid
     * @return int
     */
    public static function attempts_left(stdClass $instance, int $userid): int {
        global $DB;
        if (empty($instance->maxattempts)) {
            return -1;
        }
        $used = $DB->count_records(
            'aiinteractivevideo_attempt',
            [
                'aiinteractivevideoid' => $instance->id,
                'userid' => $userid,
                'mode' => self::graded_mode($instance),
                'state' => self::STATE_FINISHED,
            ]
        );
        return max(0, (int)$instance->maxattempts - $used);
    }

    /**
     * Starts a new attempt or resumes the one in progress.
     *
     * @param stdClass $instance
     * @param int $userid
     * @param string $mode learn|test
     * @param context_module $context
     * @return stdClass attempt
     * @throws \moodle_exception
     */
    public static function start_attempt(stdClass $instance, int $userid, string $mode, context_module $context): stdClass {
        global $DB;
        if (!in_array($mode, ['learn', 'test'], true) || empty($instance->{'allow' . $mode})) {
            throw new \moodle_exception('invalidmode', 'mod_aiinteractivevideo');
        }
        if (!self::get_sections($instance->id)) {
            throw new \moodle_exception('notready', 'mod_aiinteractivevideo');
        }
        $existing = $DB->get_records(
            'aiinteractivevideo_attempt',
            [
                'aiinteractivevideoid' => $instance->id,
                'userid' => $userid,
                'mode' => $mode,
                'state' => self::STATE_INPROGRESS,
            ],
            'id DESC',
            '*',
            0,
            1
        );
        if ($existing) {
            return reset($existing);
        }
        if ($mode === self::graded_mode($instance) && self::attempts_left($instance, $userid) === 0) {
            throw new \moodle_exception('nomoreattempts', 'mod_aiinteractivevideo');
        }
        $last = (int)$DB->get_field_sql(
            'SELECT MAX(attempt) FROM {aiinteractivevideo_attempt}
              WHERE aiinteractivevideoid = :id AND userid = :userid AND mode = :mode',
            ['id' => $instance->id, 'userid' => $userid, 'mode' => $mode]
        );
        $now = time();
        $attempt = (object)[
            'aiinteractivevideoid' => $instance->id,
            'userid' => $userid,
            'attempt' => $last + 1,
            'mode' => $mode,
            'state' => self::STATE_INPROGRESS,
            'salt' => random_string(24),
            'score' => 0,
            'maxscore' => count(self::get_sections($instance->id)),
            'position' => 0,
            'maxwatched' => 0,
            'activetime' => 0,
            'timestart' => $now,
            'timefinish' => 0,
            'timemodified' => $now,
        ];
        $attempt->id = $DB->insert_record('aiinteractivevideo_attempt', $attempt);
        \mod_aiinteractivevideo\event\attempt_started::create(
            [
                'objectid' => $attempt->id,
                'context' => $context,
                'relateduserid' => $userid,
                'other' => ['mode' => $mode],
            ]
        )->trigger();
        return $attempt;
    }

    /**
     * An attempt's responses keyed by section id (one response per section per attempt).
     *
     * @param int $attemptid
     * @return stdClass[]
     */
    public static function responses_by_section(int $attemptid): array {
        global $DB;
        $bysection = [];
        foreach ($DB->get_records('aiinteractivevideo_response', ['attemptid' => $attemptid]) as $response) {
            $bysection[$response->sectionid] = $response;
        }
        return $bysection;
    }

    /**
     * Learner-facing data for an attempt: sections with payloads and progress.
     *
     * @param stdClass $instance
     * @param stdClass $attempt
     * @return array
     */
    public static function attempt_data(stdClass $instance, stdClass $attempt): array {
        global $DB;
        $responses = self::responses_by_section((int)$attempt->id);
        $sections = [];
        $index = 0;
        foreach (self::get_sections($instance->id) as $section) {
            $r = $responses[$section->id] ?? null;
            $payload = interaction::payload($section, $attempt->salt);
            $sections[] = [
                'id' => (int)$section->id,
                'index' => $index++,
                'title' => $section->title,
                'start' => (float)$section->starttime,
                'end' => (float)$section->endtime,
                'type' => $section->type,
                'status' => self::status($r),
                'points' => $r ? (int)$r->points : 0,
                'tries' => $r ? (int)$r->tries : 0,
                'hashint' => trim((string)$section->hint) !== '' || interaction::max_hints($section, $attempt->salt) > 0,
            ] + $payload;
        }
        return [
            'attemptid' => (int)$attempt->id,
            'attempt' => (int)$attempt->attempt,
            'mode' => $attempt->mode,
            'position' => (float)$attempt->position,
            'maxwatched' => (float)$attempt->maxwatched,
            'activetime' => (int)$attempt->activetime,
            'score' => self::score($attempt),
            'maxscore' => count($sections),
            'sections' => $sections,
        ];
    }

    /**
     * Status word for a response.
     *
     * @param stdClass|null $r
     * @return string pending|solved|revealed|skipped
     */
    public static function status(?stdClass $r): string {
        if (!$r) {
            return 'pending';
        }
        if (!empty($r->revealed)) {
            return 'revealed';
        }
        if (!empty($r->solved)) {
            return 'solved';
        }
        return !empty($r->skipped) ? 'skipped' : 'pending';
    }

    /**
     * Loads an attempt owned by the user and still in progress, with its activity and course module.
     *
     * @param int $attemptid
     * @param int $userid
     * @return array [attempt, instance, cm, course, context]
     * @throws \moodle_exception
     */
    public static function own_attempt(int $attemptid, int $userid): array {
        global $DB;
        $attempt = $DB->get_record('aiinteractivevideo_attempt', ['id' => $attemptid], '*', MUST_EXIST);
        if ((int)$attempt->userid !== $userid) {
            throw new \moodle_exception('notyourattempt', 'mod_aiinteractivevideo');
        }
        $instance = $DB->get_record('aiinteractivevideo', ['id' => $attempt->aiinteractivevideoid], '*', MUST_EXIST);
        [$course, $cm] = get_course_and_cm_from_instance($instance->id, 'aiinteractivevideo');
        $context = context_module::instance($cm->id);
        return [$attempt, $instance, $cm, $course, $context];
    }

    /**
     * Throws unless the attempt is still in progress.
     *
     * @param stdClass $attempt
     * @throws \moodle_exception
     */
    public static function require_open(stdClass $attempt): void {
        if ($attempt->state !== self::STATE_INPROGRESS) {
            throw new \moodle_exception('attemptclosed', 'mod_aiinteractivevideo');
        }
    }

    /**
     * Loads a section that belongs to the attempt's activity.
     *
     * @param stdClass $attempt
     * @param int $sectionid
     * @return stdClass
     */
    public static function attempt_section(stdClass $attempt, int $sectionid): stdClass {
        global $DB;
        return $DB->get_record(
            'aiinteractivevideo_section',
            ['id' => $sectionid, 'aiinteractivevideoid' => $attempt->aiinteractivevideoid],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Gets (or creates) the response row for a section.
     *
     * @param stdClass $attempt
     * @param stdClass $section
     * @return stdClass
     */
    public static function response(stdClass $attempt, stdClass $section): stdClass {
        global $DB;
        $r = $DB->get_record('aiinteractivevideo_response', ['attemptid' => $attempt->id, 'sectionid' => $section->id]);
        if ($r) {
            return $r;
        }
        $now = time();
        $r = (object)[
            'attemptid' => $attempt->id,
            'sectionid' => $section->id,
            'tries' => 0,
            'solved' => 0,
            'firsttry' => 0,
            'points' => 0,
            'hintsused' => 0,
            'revealed' => 0,
            'skipped' => 0,
            'rewatches' => 0,
            'timespent' => 0,
            'response' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $r->id = $DB->insert_record('aiinteractivevideo_response', $r);
        return $r;
    }

    /**
     * Current attempt score (sum of points).
     *
     * @param stdClass $attempt
     * @return int
     */
    public static function score(stdClass $attempt): int {
        global $DB;
        return (int)$DB->get_field_sql(
            'SELECT COALESCE(SUM(points), 0) FROM {aiinteractivevideo_response} WHERE attemptid = :id',
            ['id' => $attempt->id]
        );
    }

    /**
     * Marks a response.
     *
     * @param stdClass $attempt
     * @param stdClass $instance
     * @param stdClass $section
     * @param array $response list of key/value pairs
     * @param int $timespent seconds on this try
     * @return array
     */
    public static function submit(
        stdClass $attempt, stdClass $instance, stdClass $section, array $response,
        int $timespent
    ): array {
        global $DB;
        $r = self::response($attempt, $section);
        $locked = $r->solved || $r->revealed || $r->skipped;
        $mark = interaction::mark($section, $attempt->salt, $response);
        if (!$locked) {
            $r->tries++;
            $r->timespent += max(0, min(900, $timespent));
            $r->response = json_encode(array_values($response));
            if ($mark['correct']) {
                $r->solved = 1;
                $r->firsttry = ($r->tries == 1 && $r->hintsused == 0) ? 1 : 0;
                if ((int)$instance->scoremode === 2) {
                    $r->points = ($r->hintsused == 0 && !$r->revealed) ? 1 : 0;
                } else {
                    $r->points = $r->firsttry;
                }
            } else if ($attempt->mode === 'test' && $instance->maxtries > 0 && $r->tries >= $instance->maxtries) {
                $r->skipped = 1;
            }
            $r->timemodified = time();
            $DB->update_record('aiinteractivevideo_response', $r);
            $DB->set_field('aiinteractivevideo_attempt', 'timemodified', time(), ['id' => $attempt->id]);
        }
        $triesleft = -1;
        if ($attempt->mode === 'test' && $instance->maxtries > 0) {
            $triesleft = max(0, (int)$instance->maxtries - (int)$r->tries);
        }
        return [
            'correct' => (bool)$mark['correct'],
            'points' => (int)$r->points,
            'tries' => (int)$r->tries,
            'triesleft' => $triesleft,
            'status' => self::status($r),
            'score' => self::score($attempt),
            'results' => $mark['results'],
            'missing' => (int)$mark['missing'],
            'feedback' => (string)($mark['correct'] ? $section->feedbackcorrect : $section->feedbackwrong),
            'rewatchfrom' => (float)$section->starttime,
        ];
    }

    /**
     * Uses a hint (learn mode only).
     *
     * @param stdClass $attempt
     * @param stdClass $section
     * @return array
     * @throws \moodle_exception
     */
    public static function hint(stdClass $attempt, stdClass $section): array {
        global $DB;
        if ($attempt->mode !== 'learn') {
            throw new \moodle_exception('learnonly', 'mod_aiinteractivevideo');
        }
        $r = self::response($attempt, $section);
        $max = interaction::max_hints($section, $attempt->salt);
        if (!$r->solved && !$r->revealed) {
            $r->hintsused = min($r->hintsused + 1, max(1, $max));
            $r->points = 0;
            $r->timemodified = time();
            $DB->update_record('aiinteractivevideo_response', $r);
        }
        return [
            'text' => (string)$section->hint,
            'assist' => interaction::assist($section, $attempt->salt, (int)$r->hintsused),
            'hintsused' => (int)$r->hintsused,
            'hintsleft' => max(0, $max - (int)$r->hintsused),
        ];
    }

    /**
     * Shows the answer (learn mode only). The interaction then scores no point.
     *
     * @param stdClass $attempt
     * @param stdClass $section
     * @return array
     * @throws \moodle_exception
     */
    public static function reveal(stdClass $attempt, stdClass $section): array {
        global $DB;
        if ($attempt->mode !== 'learn') {
            throw new \moodle_exception('learnonly', 'mod_aiinteractivevideo');
        }
        $r = self::response($attempt, $section);
        if (!$r->solved) {
            $r->revealed = 1;
            $r->points = 0;
            $r->timemodified = time();
            $DB->update_record('aiinteractivevideo_response', $r);
        }
        return [
            'solution' => interaction::solution($section, $attempt->salt),
            'feedback' => (string)$section->feedbackcorrect,
            'score' => self::score($attempt),
        ];
    }

    /**
     * Records a rewatch of a section.
     *
     * @param stdClass $attempt
     * @param stdClass $section
     */
    public static function rewatch(stdClass $attempt, stdClass $section): void {
        global $DB;
        $r = self::response($attempt, $section);
        $r->rewatches++;
        $r->timemodified = time();
        $DB->update_record('aiinteractivevideo_response', $r);
    }

    /**
     * Saves the video position and active time.
     *
     * @param stdClass $attempt
     * @param stdClass $instance
     * @param float $position
     * @param int $elapsed active seconds since the last save
     * @param int $duration video length reported by the player
     */
    public static function progress(
        stdClass $attempt, stdClass $instance, float $position, int $elapsed,
        int $duration
    ): void {
        global $DB;
        $attempt->position = max(0, min(86400, $position));
        $attempt->maxwatched = max((float)$attempt->maxwatched, $attempt->position);
        $attempt->activetime += max(0, min(120, $elapsed));
        $attempt->timemodified = time();
        $DB->update_record('aiinteractivevideo_attempt', $attempt);
        if ($duration > 0 && $duration <= 86400 && (int)$instance->videoduration !== $duration) {
            $DB->set_field('aiinteractivevideo', 'videoduration', $duration, ['id' => $instance->id]);
        }
    }

    /**
     * Finishes an attempt, grades it and returns the results.
     *
     * @param stdClass $attempt
     * @param stdClass $instance
     * @param \cm_info|stdClass $cm
     * @param stdClass $course
     * @param context_module $context
     * @param int $elapsed last active seconds
     * @return array summary
     */
    public static function finish(
        stdClass $attempt, stdClass $instance, $cm, stdClass $course, context_module $context,
        int $elapsed
    ): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/aiinteractivevideo/lib.php');
        require_once($CFG->libdir . '/completionlib.php');
        if ($attempt->state === self::STATE_INPROGRESS) {
            $attempt->state = self::STATE_FINISHED;
            $attempt->score = self::score($attempt);
            $attempt->maxscore = count(self::get_sections($instance->id));
            $attempt->activetime += max(0, min(120, $elapsed));
            $attempt->timefinish = time();
            $attempt->timemodified = $attempt->timefinish;
            $DB->update_record('aiinteractivevideo_attempt', $attempt);
            if ($attempt->mode === self::graded_mode($instance)) {
                aiinteractivevideo_update_grades($instance, (int)$attempt->userid);
            }
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, (int)$attempt->userid);
            }
            \mod_aiinteractivevideo\event\attempt_finished::create(
                [
                    'objectid' => $attempt->id,
                    'context' => $context,
                    'relateduserid' => $attempt->userid,
                    'other' => ['mode' => $attempt->mode, 'score' => (int)$attempt->score, 'maxscore' => (int)$attempt->maxscore],
                ]
            )->trigger();
        }
        return self::summary($attempt, $instance);
    }

    /**
     * Results of a finished attempt.
     *
     * @param stdClass $attempt
     * @param stdClass $instance
     * @return array
     */
    public static function summary(stdClass $attempt, stdClass $instance): array {
        global $DB;
        $responses = self::responses_by_section((int)$attempt->id);
        $rows = [];
        $firsttry = 0;
        $hints = 0;
        $reveals = 0;
        $rewatches = 0;
        foreach (self::get_sections($instance->id) as $section) {
            $r = $responses[$section->id] ?? null;
            $firsttry += $r ? (int)$r->firsttry : 0;
            $hints += $r ? (int)$r->hintsused : 0;
            $reveals += $r ? (int)$r->revealed : 0;
            $rewatches += $r ? (int)$r->rewatches : 0;
            $rows[] = [
                'title' => $section->title,
                'type' => $section->type,
                'status' => self::status($r),
                'points' => $r ? (int)$r->points : 0,
                'tries' => $r ? (int)$r->tries : 0,
                'hints' => $r ? (int)$r->hintsused : 0,
                'timespent' => $r ? (int)$r->timespent : 0,
                'start' => (float)$section->starttime,
            ];
        }
        $max = count($rows);
        $score = (int)$attempt->score;
        $graded = $attempt->mode === self::graded_mode($instance);
        return [
            'attemptid' => (int)$attempt->id,
            'mode' => $attempt->mode,
            'score' => $score,
            'maxscore' => $max,
            'percent' => $max ? round($score / $max * 100, 1) : 0,
            'duration' => (int)$attempt->activetime,
            'firsttry' => $firsttry,
            'hints' => $hints,
            'reveals' => $reveals,
            'rewatches' => $rewatches,
            'graded' => $graded,
            'attemptsleft' => $graded ? self::attempts_left($instance, (int)$attempt->userid) : -1,
            'sections' => $rows,
            'leaderboard' => !empty($instance->leaderboard) ? self::leaderboard($instance, (int)$attempt->userid) : [],
        ];
    }

    /**
     * Leaderboard: each learner's best finished attempt in the graded mode (score, then fastest time).
     *
     * @param stdClass $instance
     * @param int $userid current user (always included)
     * @param int $size
     * @return array
     */
    public static function leaderboard(stdClass $instance, int $userid, int $size = 10): array {
        global $DB;
        $best = [];
        $rs = $DB->get_recordset(
            'aiinteractivevideo_attempt',
            [
                'aiinteractivevideoid' => $instance->id,
                'mode' => self::graded_mode($instance),
                'state' => self::STATE_FINISHED,
            ],
            '',
            'id, userid, score, maxscore, activetime'
        );
        foreach ($rs as $a) {
            $b = $best[$a->userid] ?? null;
            if (!$b || $a->score > $b->score || ($a->score == $b->score && $a->activetime < $b->activetime)) {
                $best[$a->userid] = $a;
            }
        }
        $rs->close();
        uasort($best, fn($x, $y) => [$y->score, $x->activetime] <=> [$x->score, $y->activetime]);
        $rank = 0;
        $rows = [];
        $userids = array_keys($best);
        $names = [];
        if ($userids) {
            $top = array_slice($userids, 0, $size);
            if (!in_array($userid, $top) && isset($best[$userid])) {
                $top[] = $userid;
            }
            $fields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
            [$insql, $params] = $DB->get_in_or_equal($top, SQL_PARAMS_NAMED);
            $names = $DB->get_records_select('user', "id $insql", $params, '', 'id, ' . $fields);
        }
        foreach ($best as $uid => $a) {
            $rank++;
            if ($rank > $size && (int)$uid !== $userid) {
                continue;
            }
            $u = $names[$uid] ?? null;
            $name = $u ? trim($u->firstname . ' ' . \core_text::substr((string)$u->lastname, 0, 1) . '.') : '?';
            $rows[] = [
                'rank' => $rank,
                'name' => $name,
                'score' => (int)$a->score,
                'maxscore' => (int)$a->maxscore,
                'duration' => (int)$a->activetime,
                'me' => (int)$uid === $userid,
            ];
        }
        return $rows;
    }

    /**
     * Grades for the gradebook according to the grading method.
     *
     * @param stdClass $instance
     * @param int $userid 0 = all
     * @return array userid => stdClass(userid, rawgrade)
     */
    public static function user_grades(stdClass $instance, int $userid = 0): array {
        global $DB;
        $params = [
            'aiinteractivevideoid' => $instance->id,
            'mode' => self::graded_mode($instance),
            'state' => self::STATE_FINISHED,
        ];
        if ($userid) {
            $params['userid'] = $userid;
        }
        $byuser = [];
        $rs = $DB->get_recordset('aiinteractivevideo_attempt', $params, 'attempt ASC', 'id, userid, attempt, score, maxscore');
        foreach ($rs as $a) {
            $fraction = $a->maxscore > 0 ? $a->score / $a->maxscore : 0;
            $byuser[$a->userid][] = $fraction;
        }
        $rs->close();
        $grades = [];
        foreach ($byuser as $uid => $fractions) {
            switch ((int)$instance->grademethod) {
                case MOD_AIINTERACTIVEVIDEO_GRADEAVERAGE:
                    $value = array_sum($fractions) / count($fractions);
                    break;
                case MOD_AIINTERACTIVEVIDEO_GRADEFIRST:
                    $value = reset($fractions);
                    break;
                case MOD_AIINTERACTIVEVIDEO_GRADELAST:
                    $value = end($fractions);
                    break;
                default:
                    $value = max($fractions);
            }
            $grades[$uid] = (object)['userid' => $uid, 'rawgrade' => round($value * $instance->grade, 5)];
        }
        return $grades;
    }

    /**
     * The user's standing, for the landing page.
     *
     * @param stdClass $instance
     * @param int $userid
     * @return array
     */
    public static function user_summary(stdClass $instance, int $userid): array {
        global $DB;
        $out = ['learn' => null, 'test' => null, 'inprogress' => []];
        $rs = $DB->get_recordset(
            'aiinteractivevideo_attempt',
            ['aiinteractivevideoid' => $instance->id, 'userid' => $userid],
            'id ASC',
            'id, mode, state, score, maxscore'
        );
        foreach ($rs as $a) {
            if ($a->state === self::STATE_INPROGRESS) {
                $out['inprogress'][$a->mode] = true;
                continue;
            }
            $pct = $a->maxscore > 0 ? round($a->score / $a->maxscore * 100) : 0;
            $current = $out[$a->mode];
            if ($current === null || $pct > $current['percent']) {
                $out[$a->mode] = ['percent' => $pct, 'score' => (int)$a->score, 'maxscore' => (int)$a->maxscore];
            }
        }
        $rs->close();
        $out['attemptsleft'] = self::attempts_left($instance, $userid);
        return $out;
    }

    /**
     * Per-interaction statistics for the report.
     *
     * @param stdClass $instance
     * @param string $mode
     * @param int[]|null $userids restrict to these users (null = everyone)
     * @return array sectionid => stdClass
     */
    public static function section_stats(stdClass $instance, string $mode, ?array $userids = null): array {
        global $DB;
        $params = ['id' => $instance->id, 'mode' => $mode];
        $usersql = '';
        if ($userids !== null) {
            if (!$userids) {
                return [];
            }
            [$insql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
            $usersql = " AND a.userid $insql";
            $params += $uparams;
        }
        $sql = "SELECT r.sectionid,
                       COUNT(r.id) AS learners,
                       SUM(r.firsttry) AS firsttry,
                       SUM(r.solved) AS solved,
                       SUM(r.revealed) AS revealed,
                       SUM(r.skipped) AS skipped,
                       SUM(r.tries) AS tries,
                       SUM(r.hintsused) AS hints,
                       SUM(r.rewatches) AS rewatches,
                       SUM(r.timespent) AS timespent,
                       SUM(CASE WHEN r.tries > 0 THEN 1 ELSE 0 END) AS answered
                  FROM {aiinteractivevideo_response} r
                  JOIN {aiinteractivevideo_attempt} a ON a.id = r.attemptid
                 WHERE a.aiinteractivevideoid = :id AND a.mode = :mode $usersql
              GROUP BY r.sectionid";
        return $DB->get_records_sql($sql, $params);
    }
}
