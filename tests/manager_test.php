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

namespace mod_aiinteractivevideo;

use mod_aiinteractivevideo\local\interaction;
use mod_aiinteractivevideo\local\manager;

/**
 * Tests for attempts, marking, scoring, grades, completion, leaderboard and reports.
 *
 * @package    mod_aiinteractivevideo
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aiinteractivevideo\local\manager
 */
final class manager_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $instance;
    /** @var \cm_info */
    private $cm;
    /** @var \context_module */
    private $context;

    /**
     * Creates a course, an activity from the real transcript and returns a student.
     *
     * @param array $settings
     * @return \stdClass student
     */
    private function setup_activity(array $settings = []): \stdClass {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->instance = $this->getDataGenerator()->create_module(
            'aiinteractivevideo',
            $settings + [
                'course' => $this->course->id,
                'transcript' => file_get_contents(__DIR__ . '/fixtures/transcript_youtube.txt'),
                'numinteractions' => 4,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionfinish' => 1,
            ]
        );
        $this->cm = get_fast_modinfo($this->course)->get_cm($this->instance->cmid);
        $this->context = \context_module::instance($this->cm->id);
        $this->instance = $this->db_instance();
        return $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Fresh activity record.
     *
     * @return \stdClass
     */
    private function db_instance(): \stdClass {
        global $DB;
        return $DB->get_record('aiinteractivevideo', ['id' => $this->instance->id]);
    }

    /**
     * The correct response for a section in an attempt.
     *
     * @param \stdClass $attempt
     * @param \stdClass $section
     * @return array
     */
    private function right(\stdClass $attempt, \stdClass $section): array {
        return array_map(
            fn($p) => ['key' => $p['key'], 'value' => $p['value']],
            interaction::solution($section, $attempt->salt)
        );
    }

    /**
     * Creating the activity builds the interactions and sets the maximum grade to one point each.
     */
    public function test_create_builds_sections_and_grade(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->setup_activity();
        $this->assertCount(4, manager::get_sections($this->instance->id));
        $this->assertEquals(4, (float)$this->instance->grade);
        $item = \grade_item::fetch(
            ['itemtype' => 'mod', 'itemmodule' => 'aiinteractivevideo',
            'iteminstance' => $this->instance->id, 'courseid' => $this->course->id]
        );
        $this->assertEquals(4, (float)$item->grademax);
        $this->assertEquals('ruM4Xxhx32U', $this->instance->videoid);
    }

    /**
     * A full test attempt: first-try points, wrong answer then right (no point), grade and completion.
     */
    public function test_test_attempt_scoring_grade_completion(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $student = $this->setup_activity();
        $this->setUser($student);
        $attempt = manager::start_attempt($this->instance, $student->id, 'test', $this->context);
        $sections = array_values(manager::get_sections($this->instance->id));

        $res = manager::submit($attempt, $this->instance, $sections[0], $this->right($attempt, $sections[0]), 12);
        $this->assertTrue($res['correct']);
        $this->assertEquals(1, $res['points']);

        $res = manager::submit($attempt, $this->instance, $sections[1], [], 5);
        $this->assertFalse($res['correct']);
        $this->assertEquals('pending', $res['status']);
        $this->assertEquals(2, $res['triesleft']);
        $this->assertEquals($sections[1]->starttime, $res['rewatchfrom']);
        manager::rewatch($attempt, $sections[1]);
        $res = manager::submit($attempt, $this->instance, $sections[1], $this->right($attempt, $sections[1]), 5);
        $this->assertTrue($res['correct']);
        $this->assertEquals(0, $res['points'], 'No point after a wrong first try');

        foreach ([2, 3] as $i) {
            manager::submit($attempt, $this->instance, $sections[$i], $this->right($attempt, $sections[$i]), 3);
        }
        // Resubmitting a solved interaction changes nothing.
        $res = manager::submit($attempt, $this->instance, $sections[0], [], 3);
        $this->assertEquals(1, $res['points']);

        $summary = manager::finish($attempt, $this->instance, $this->cm, $this->course, $this->context, 4);
        $this->assertEquals(3, $summary['score']);
        $this->assertEquals(4, $summary['maxscore']);
        $this->assertEquals(75.0, $summary['percent']);
        $this->assertEquals(1, $summary['rewatches']);
        $this->assertEquals('solved', $summary['sections'][1]['status']);

        $grades = grade_get_grades($this->course->id, 'mod', 'aiinteractivevideo', $this->instance->id, $student->id);
        $this->assertEquals(3, (float)$grades->items[0]->grades[$student->id]->grade);

        $completion = new \completion_info($this->course);
        $data = $completion->get_data($this->cm, false, $student->id);
        $this->assertEquals(COMPLETION_COMPLETE, $data->customcompletion['completionfinish'] ?? $data->completionstate);

        // Finished attempts cannot be changed.
        $closed = manager::own_attempt($attempt->id, $student->id)[0];
        $this->expectException(\moodle_exception::class);
        manager::require_open($closed);
    }

    /**
     * The try limit moves the learner on without the point.
     */
    public function test_try_limit(): void {
        $student = $this->setup_activity(['maxtries' => 2]);
        $attempt = manager::start_attempt($this->instance, $student->id, 'test', $this->context);
        $section = array_values(manager::get_sections($this->instance->id))[0];
        manager::submit($attempt, $this->instance, $section, [], 1);
        $res = manager::submit($attempt, $this->instance, $section, [], 1);
        $this->assertEquals('skipped', $res['status']);
        $this->assertEquals(0, $res['triesleft']);
        $res = manager::submit($attempt, $this->instance, $section, $this->right($attempt, $section), 1);
        $this->assertEquals(0, $res['points'], 'Locked after the try limit');
    }

    /**
     * Score mode 2 gives the point after a replay.
     */
    public function test_score_any_try(): void {
        $student = $this->setup_activity(['scoremode' => 2]);
        $attempt = manager::start_attempt($this->instance, $student->id, 'test', $this->context);
        $section = array_values(manager::get_sections($this->instance->id))[0];
        manager::submit($attempt, $this->instance, $section, [], 1);
        $res = manager::submit($attempt, $this->instance, $section, $this->right($attempt, $section), 1);
        $this->assertEquals(1, $res['points']);
    }

    /**
     * Hints and answers work in Learn mode only and cost the point.
     */
    public function test_learn_mode_hints_and_reveal(): void {
        $student = $this->setup_activity();
        $learn = manager::start_attempt($this->instance, $student->id, 'learn', $this->context);
        $sections = array_values(manager::get_sections($this->instance->id));
        $hint = manager::hint($learn, $sections[0]);
        $this->assertEquals(1, $hint['hintsused']);
        $this->assertNotEmpty($hint['assist']);
        $res = manager::submit($learn, $this->instance, $sections[0], $this->right($learn, $sections[0]), 1);
        $this->assertTrue($res['correct']);
        $this->assertEquals(0, $res['points']);
        $reveal = manager::reveal($learn, $sections[1]);
        $this->assertEquals(interaction::solution($sections[1], $learn->salt), $reveal['solution']);
        $summary = manager::finish($learn, $this->instance, $this->cm, $this->course, $this->context, 0);
        $this->assertEquals('revealed', $summary['sections'][1]['status']);
        $this->assertFalse($summary['graded']);

        $test = manager::start_attempt($this->instance, $student->id, 'test', $this->context);
        $this->expectException(\moodle_exception::class);
        manager::hint($test, $sections[0]);
    }

    /**
     * Attempts resume, and the attempt limit is enforced.
     */
    public function test_resume_and_attempt_limit(): void {
        $student = $this->setup_activity(['maxattempts' => 1]);
        $a1 = manager::start_attempt($this->instance, $student->id, 'test', $this->context);
        manager::progress($a1, $this->instance, 42.5, 10, 267);
        $again = manager::start_attempt($this->instance, $student->id, 'test', $this->context);
        $this->assertEquals($a1->id, $again->id);
        $this->assertEquals(42.5, (float)$again->position);
        $this->assertEquals(267, (int)$this->db_instance()->videoduration);
        manager::finish($again, $this->instance, $this->cm, $this->course, $this->context, 0);
        $this->assertEquals(0, manager::attempts_left($this->instance, $student->id));
        $this->expectException(\moodle_exception::class);
        manager::start_attempt($this->instance, $student->id, 'test', $this->context);
    }

    /**
     * Leaderboard ranks by points then time, and grading methods apply.
     */
    public function test_leaderboard_and_grading_methods(): void {
        global $DB;
        $student = $this->setup_activity();
        $other = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['firstname' => 'Ana',
            'lastname' => 'Smith']
        );
        $sections = array_values(manager::get_sections($this->instance->id));
        foreach ([[$student, 2, 100], [$other, 4, 300], [$student, 4, 200]] as [$user, $correct, $time]) {
            $attempt = manager::start_attempt($this->instance, $user->id, 'test', $this->context);
            foreach ($sections as $i => $section) {
                manager::submit($attempt, $this->instance, $section, $i < $correct ? $this->right($attempt, $section) : [], 1);
            }
            manager::finish($attempt, $this->instance, $this->cm, $this->course, $this->context, 0);
            $DB->set_field('aiinteractivevideo_attempt', 'activetime', $time, ['id' => $attempt->id]);
        }
        $board = manager::leaderboard($this->instance, $student->id);
        $this->assertCount(2, $board);
        $this->assertTrue($board[0]['me'], 'Same score, faster time ranks first');
        $this->assertEquals('Ana S.', $board[1]['name']);

        $this->assertEquals(4.0, manager::user_grades($this->instance, $student->id)[$student->id]->rawgrade);
        $this->instance->grademethod = MOD_AIINTERACTIVEVIDEO_GRADEFIRST;
        $this->assertEquals(2.0, manager::user_grades($this->instance, $student->id)[$student->id]->rawgrade);
        $this->instance->grademethod = MOD_AIINTERACTIVEVIDEO_GRADEAVERAGE;
        $this->assertEquals(3.0, manager::user_grades($this->instance, $student->id)[$student->id]->rawgrade);

        $stats = manager::section_stats($this->instance, 'test');
        $this->assertEquals(3, $stats[$sections[0]->id]->answered);
        $this->assertEquals(3, $stats[$sections[0]->id]->firsttry);
        $this->assertEquals(2, $stats[$sections[3]->id]->firsttry);
    }

    /**
     * Another user's attempt cannot be used.
     */
    public function test_own_attempt(): void {
        $student = $this->setup_activity();
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $attempt = manager::start_attempt($this->instance, $student->id, 'test', $this->context);
        $this->expectException(\moodle_exception::class);
        manager::own_attempt($attempt->id, $other->id);
    }

    /**
     * Deleting the activity removes everything.
     */
    public function test_delete_instance(): void {
        global $CFG, $DB;
        $student = $this->setup_activity();
        $attempt = manager::start_attempt($this->instance, $student->id, 'test', $this->context);
        $section = array_values(manager::get_sections($this->instance->id))[0];
        manager::submit($attempt, $this->instance, $section, [], 1);
        require_once($CFG->dirroot . '/mod/aiinteractivevideo/lib.php');
        $this->assertTrue(aiinteractivevideo_delete_instance($this->instance->id));
        $this->assertFalse($DB->record_exists('aiinteractivevideo', ['id' => $this->instance->id]));
        $this->assertFalse($DB->record_exists('aiinteractivevideo_section', ['aiinteractivevideoid' => $this->instance->id]));
        $this->assertFalse($DB->record_exists('aiinteractivevideo_attempt', ['aiinteractivevideoid' => $this->instance->id]));
        $this->assertFalse($DB->record_exists('aiinteractivevideo_response', ['attemptid' => $attempt->id]));
    }
}
