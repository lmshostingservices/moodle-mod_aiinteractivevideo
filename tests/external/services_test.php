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
use externallib_advanced_testcase;
use mod_aiinteractivevideo\local\interaction;
use mod_aiinteractivevideo\local\manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests for the learner and teacher web services.
 *
 * @package    mod_aiinteractivevideo
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aiinteractivevideo\external\start_attempt
 * @covers     \mod_aiinteractivevideo\external\submit_response
 * @covers     \mod_aiinteractivevideo\external\finish_attempt
 * @covers     \mod_aiinteractivevideo\external\get_hint
 * @covers     \mod_aiinteractivevideo\external\generate
 */
final class services_test extends externallib_advanced_testcase {
    /**
     * A learner plays through the services; answers are never sent to the browser.
     */
    public function test_learner_flow(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module(
            'aiinteractivevideo',
            ['course' => $course->id,
            'numinteractions' => 2]
        );
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $data = start_attempt::execute($instance->cmid, 'test');
        $data = external_api::clean_returnvalue(start_attempt::execute_returns(), $data);
        $this->assertCount(2, $data['sections']);
        $this->assertStringNotContainsString('"correct"', json_encode($data));

        $attempt = $DB->get_record('aiinteractivevideo_attempt', ['id' => $data['attemptid']]);
        foreach ($data['sections'] as $s) {
            $section = $DB->get_record('aiinteractivevideo_section', ['id' => $s['id']]);
            $response = array_map(
                fn($p) => ['key' => $p['key'], 'value' => $p['value']],
                interaction::solution($section, $attempt->salt)
            );
            $res = submit_response::execute($data['attemptid'], $s['id'], $response, 10);
            $res = external_api::clean_returnvalue(submit_response::execute_returns(), $res);
            $this->assertTrue($res['correct']);
        }
        save_progress::execute($data['attemptid'], 30.5, 20, 267);
        $summary = finish_attempt::execute($data['attemptid'], 5);
        $summary = external_api::clean_returnvalue(finish_attempt::execute_returns(), $summary);
        $this->assertEquals(2, $summary['score']);
        $this->assertEquals(25, $summary['duration']);
        $this->assertCount(1, $summary['leaderboard']);

        // Hints work in Learn mode.
        $new = start_attempt::execute($instance->cmid, 'learn');
        $hint = get_hint::execute($new['attemptid'], $data['sections'][0]['id']);
        $this->assertArrayHasKey('assist', $hint);
    }

    /**
     * Other users cannot use someone else's attempt, and sections from other activities are refused.
     */
    public function test_attempt_ownership(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $a = $this->getDataGenerator()->create_module('aiinteractivevideo', ['course' => $course->id, 'numinteractions' => 2]);
        $b = $this->getDataGenerator()->create_module('aiinteractivevideo', ['course' => $course->id, 'numinteractions' => 2]);
        $s1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $s2 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($s1);
        $data = start_attempt::execute($a->cmid, 'test');
        $other = array_values(manager::get_sections($b->id))[0];
        try {
            submit_response::execute($data['attemptid'], $other->id, [], 1);
            $this->fail('A section of another activity was accepted');
        } catch (\dml_missing_record_exception $e) {
            $this->assertInstanceOf(\dml_missing_record_exception::class, $e);
        }
        $this->setUser($s2);
        $this->expectException(\moodle_exception::class);
        submit_response::execute($data['attemptid'], $data['sections'][0]['id'], [], 1);
    }

    /**
     * Students cannot rebuild interactions; teachers can.
     */
    public function test_generate_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module(
            'aiinteractivevideo',
            ['course' => $course->id,
            'numinteractions' => 2]
        );
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        // Unknown actions are rejected.
        try {
            generate::execute($instance->cmid, 'builtin');
            $this->fail('Unknown actions must be rejected');
        } catch (\invalid_parameter_exception $e) {
            $this->assertEquals(2, $DB->count_records('aiinteractivevideo_section', ['aiinteractivevideoid' => $instance->id]));
        }
        // Nothing requested: "continue" sends nothing and reports the current interactions.
        $this->assertEquals(2, generate::execute($instance->cmid, 'continue')['count']);
        // Without LMS Labs credentials a new generation fails cleanly: interactions unchanged, reason stored.
        try {
            generate::execute($instance->cmid, 'regenerate');
            $this->fail('A generation cannot run without LMS Labs credentials');
        } catch (\moodle_exception $e) {
            $this->assertEquals(
                \mod_aiinteractivevideo\local\manager::GEN_FAILED,
                (int)$DB->get_field('aiinteractivevideo', 'genstatus', ['id' => $instance->id])
            );
            $this->assertEquals(2, $DB->count_records('aiinteractivevideo_section', ['aiinteractivevideoid' => $instance->id]));
        }
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        generate::execute($instance->cmid, 'regenerate');
    }
}
