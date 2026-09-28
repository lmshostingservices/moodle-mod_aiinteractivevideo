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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use mod_aiinteractivevideo\local\manager;

/**
 * Privacy provider tests.
 *
 * @package    mod_aiinteractivevideo
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aiinteractivevideo\privacy\provider
 */
final class provider_test extends provider_testcase {
    /** @var \stdClass */
    private $instance;
    /** @var \context_module */
    private $context;

    /**
     * Two students with attempts.
     *
     * @return array [student1, student2]
     */
    private function setup_data(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module(
            'aiinteractivevideo',
            ['course' => $course->id,
            'numinteractions' => 2]
        );
        $this->context = \context_module::instance($this->instance->cmid);
        $users = [];
        foreach ([1, 2] as $n) {
            $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $attempt = manager::start_attempt($this->instance, $user->id, 'test', $this->context);
            $section = array_values(manager::get_sections($this->instance->id))[0];
            manager::submit($attempt, $this->instance, $section, [], 7);
            $users[] = $user;
        }
        return $users;
    }

    /**
     * Metadata lists the tables, YouTube and linked subsystems.
     */
    public function test_metadata(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('mod_aiinteractivevideo'));
        $names = array_map(fn($i) => $i->get_name(), $collection->get_collection());
        $this->assertContains('aiinteractivevideo_attempt', $names);
        $this->assertContains('aiinteractivevideo_response', $names);
        $this->assertContains('youtube', $names);
        $this->assertContains('core_grades', $names);
    }

    /**
     * Contexts, users, export and deletion.
     */
    public function test_export_and_delete(): void {
        global $DB;
        [$u1, $u2] = $this->setup_data();
        $contexts = provider::get_contexts_for_userid($u1->id);
        $this->assertEquals([$this->context->id], $contexts->get_contextids());

        $userlist = new userlist($this->context, 'mod_aiinteractivevideo');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$u1->id, $u2->id], $userlist->get_userids());

        $this->export_context_data_for_user($u1->id, $this->context, 'mod_aiinteractivevideo');
        $data = writer::with_context($this->context)->get_data([get_string('attempts', 'mod_aiinteractivevideo')]);
        $this->assertCount(1, $data->attempts);
        $this->assertEquals(1, $data->attempts[0]['interactions'][0]['tries']);

        provider::delete_data_for_user(new approved_contextlist($u1, 'mod_aiinteractivevideo', [$this->context->id]));
        $this->assertFalse($DB->record_exists('aiinteractivevideo_attempt', ['userid' => $u1->id]));
        $this->assertTrue($DB->record_exists('aiinteractivevideo_attempt', ['userid' => $u2->id]));

        provider::delete_data_for_users(new approved_userlist($this->context, 'mod_aiinteractivevideo', [$u2->id]));
        $this->assertFalse($DB->record_exists('aiinteractivevideo_attempt', ['userid' => $u2->id]));
        $this->assertEquals(0, $DB->count_records('aiinteractivevideo_response'));
    }

    /**
     * Deleting a whole context.
     */
    public function test_delete_context(): void {
        global $DB;
        $this->setup_data();
        provider::delete_data_for_all_users_in_context($this->context);
        $this->assertEquals(0, $DB->count_records('aiinteractivevideo_attempt'));
        $this->assertEquals(2, $DB->count_records('aiinteractivevideo_section'));
    }
}
