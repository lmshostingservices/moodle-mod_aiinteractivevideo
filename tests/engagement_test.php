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

use mod_aiinteractivevideo\local\engagement;
use mod_aiinteractivevideo\local\interaction;
use mod_aiinteractivevideo\local\manager;

/**
 * Tests for video engagement: watched seconds, per-learner results and the class ranking.
 *
 * @package    mod_aiinteractivevideo
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aiinteractivevideo\local\engagement
 */
final class engagement_test extends \advanced_testcase {
    /**
     * Ranges merge, never double count and stay inside the video.
     */
    public function test_ranges(): void {
        $this->assertSame([[0, 30], [40, 60]], engagement::parse('40-60,0-10,5-30'));
        $this->assertSame([[0, 30]], engagement::parse('0-15,15-30'), 'Touching ranges join');
        $this->assertSame([], engagement::parse('x,10-5,  ,abc-def'));
        $this->assertSame([[0, 50]], engagement::parse('0-80', 50));
        [$text, $secs] = engagement::merge('0-30', '20-45,100-200', 120);
        $this->assertSame('0-45,100-120', $text);
        $this->assertSame(65, $secs);
        // Watching the same part again adds nothing.
        [$text, $secs] = engagement::merge($text, '0-45', 120);
        $this->assertSame(65, $secs);
        $this->assertSame(50, engagement::percent(60, 120));
        $this->assertSame(100, engagement::percent(130, 120));
        $this->assertSame(0, engagement::percent(10, 0));
        // The stored text stays bounded.
        $many = implode(',', array_map(fn($i) => ($i * 3) . '-' . ($i * 3 + 1), range(0, 999)));
        $this->assertLessThanOrEqual(engagement::MAX_RANGES, count(engagement::parse($many)));
    }

    /**
     * Interaction results from response rows.
     */
    public function test_result(): void {
        $r = fn($tries, $solved, $revealed = 0, $skipped = 0) => (object)['tries' => $tries, 'solved' => $solved,
            'revealed' => $revealed, 'skipped' => $skipped];
        $this->assertSame(engagement::RESULT_NONE, engagement::result(null));
        $this->assertSame(engagement::RESULT_NONE, engagement::result($r(0, 0)));
        $this->assertSame(engagement::RESULT_FIRST, engagement::result($r(1, 1)));
        $this->assertSame(engagement::RESULT_LATER, engagement::result($r(3, 1)));
        $this->assertSame(engagement::RESULT_WRONG, engagement::result($r(3, 0, 0, 1)));
        $this->assertSame(engagement::RESULT_WRONG, engagement::result($r(0, 0, 1)));
        $this->assertSame(engagement::RESULT_WRONG, engagement::result($r(1, 0)));
    }

    /**
     * Full class: watched time from the player, results per learner, who has not started, the most missed question.
     */
    public function test_class_report(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('aiinteractivevideo', [
            'course' => $course->id,
            'transcript' => file_get_contents(__DIR__ . '/fixtures/transcript_youtube.txt'),
            'numinteractions' => 4,
            'maxtries' => 2,
        ]);
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        $context = \context_module::instance($cm->id);
        $DB->set_field('aiinteractivevideo', 'videoduration', 200, ['id' => $instance->id]);
        $instance = $DB->get_record('aiinteractivevideo', ['id' => $instance->id]);
        $sections = array_values(manager::get_sections($instance->id));
        $right = fn($a, $s) => array_map(
            fn($p) => ['key' => $p['key'], 'value' => $p['value']],
            interaction::solution($s, $a->salt)
        );
        $gen = $this->getDataGenerator();
        $ava = $gen->create_and_enrol($course, 'student', ['firstname' => 'Ava', 'lastname' => 'Adams']);
        $ben = $gen->create_and_enrol($course, 'student', ['firstname' => 'Ben', 'lastname' => 'Brown']);
        $cat = $gen->create_and_enrol($course, 'student', ['firstname' => 'Cat', 'lastname' => 'Clark']);
        $gen->create_and_enrol($course, 'editingteacher');

        // Ava watches everything (with a replay) and gets all right first time.
        $a = manager::start_attempt($instance, $ava->id, 'test', $context);
        manager::progress($a, $instance, 120, 10, 200, '0-60,60-120');
        manager::progress($a, $instance, 200, 10, 200, '100-120,120-200');
        foreach ($sections as $s) {
            manager::submit($a, $instance, $s, $right($a, $s), 5);
        }
        manager::finish($a, $instance, $cm, $course, $context, 1);

        // Ben skips ahead (only 0-50 and 150-200 played), gets Q2 wrong then right, and runs out of tries on Q3.
        $b = manager::start_attempt($instance, $ben->id, 'test', $context);
        manager::progress($b, $instance, 200, 10, 200, '0-50,150-200');
        manager::submit($b, $instance, $sections[0], $right($b, $sections[0]), 5);
        manager::submit($b, $instance, $sections[1], [], 5);
        manager::submit($b, $instance, $sections[1], $right($b, $sections[1]), 5);
        manager::submit($b, $instance, $sections[2], [], 5);
        manager::submit($b, $instance, $sections[2], [], 5);
        manager::finish($b, $instance, $cm, $course, $context, 1, '');

        // Cat has not started.
        $rows = engagement::rows($instance, $sections, engagement::learners($context, $instance), 'test');
        $this->assertCount(3, $rows, 'Only learners, not the teacher');

        $ra = $rows[$ava->id];
        $this->assertSame('finished', $ra->status);
        $this->assertSame(200, $ra->watchedsecs, 'The replay is not counted twice');
        $this->assertSame(100, $ra->watchedpct);
        $this->assertTrue($ra->wholevideo);
        $this->assertTrue($ra->alldone);
        $this->assertSame(4, $ra->firsttry);
        $this->assertSame([], $ra->wrong);

        $rb = $rows[$ben->id];
        $this->assertSame(100, $rb->watchedsecs);
        $this->assertSame(50, $rb->watchedpct, 'Skipping ahead is not watching');
        $this->assertFalse($rb->wholevideo);
        $this->assertSame(3, $rb->answered);
        $this->assertFalse($rb->alldone);
        $this->assertSame([2, 3], $rb->wrong);
        $this->assertSame([4], $rb->notanswered);
        $this->assertSame(engagement::RESULT_LATER, $rb->results[$sections[1]->id]->result);
        $this->assertSame(engagement::RESULT_WRONG, $rb->results[$sections[2]->id]->result);

        $rc = $rows[$cat->id];
        $this->assertSame('notstarted', $rc->status);
        $this->assertSame(0, $rc->watchedpct);

        $t = engagement::totals($rows);
        $this->assertSame(3, $t->learners);
        $this->assertSame(1, $t->notstarted);
        $this->assertSame(1, $t->wholevideo);
        $this->assertSame(1, $t->alldone);
        $this->assertSame(75, $t->avgwatched, 'Average over learners who started');

        $missed = array_values(engagement::most_missed($sections, $rows));
        $this->assertSame(2, $missed[0]->number);
        $this->assertSame(1, $missed[0]->wrong);
        $this->assertSame(50, $missed[0]->wrongpct);
        $this->assertSame(['Ben Brown'], $missed[0]->names);

        // The attempt keeps the merged ranges.
        $stored = $DB->get_record('aiinteractivevideo_attempt', ['id' => $a->id]);
        $this->assertSame('0-200', $stored->watchedranges);
        $this->assertEquals(200, $stored->watchedsecs);
    }
}
