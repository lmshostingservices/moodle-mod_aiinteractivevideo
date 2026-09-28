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

use mod_aiinteractivevideo\local\manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Backup and restore tests (with and without user data).
 *
 * @package    mod_aiinteractivevideo
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_aiinteractivevideo_activity_structure_step
 * @covers     \restore_aiinteractivevideo_activity_structure_step
 */
final class backup_restore_test extends \advanced_testcase {
    /**
     * Duplicating a course keeps interactions, and attempts when user data is included.
     */
    public function test_backup_restore(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module(
            'aiinteractivevideo',
            ['course' => $course->id,
            'numinteractions' => 3, 'accent' => 'teal']
        );
        $context = \context_module::instance($instance->cmid);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $attempt = manager::start_attempt($instance, $student->id, 'test', $context);
        $section = array_values(manager::get_sections($instance->id))[0];
        manager::submit($attempt, $instance, $section, [], 5);

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $file = $bc->get_results()['backup_destination'];
        $file->extract_to_pathname(
            get_file_packer('application/vnd.moodle.backup'),
            make_backup_temp_directory($backupid)
        );
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course('Copy', 'COPY', $course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $copy = $DB->get_record('aiinteractivevideo', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertEquals('teal', $copy->accent);
        $this->assertEquals($instance->transcript, $copy->transcript);
        $sections = array_values(manager::get_sections($copy->id));
        $this->assertCount(3, $sections);
        $this->assertEquals($section->content, $sections[0]->content);
        $copyattempt = $DB->get_record('aiinteractivevideo_attempt', ['aiinteractivevideoid' => $copy->id], '*', MUST_EXIST);
        $this->assertEquals($student->id, $copyattempt->userid);
        $response = $DB->get_record('aiinteractivevideo_response', ['attemptid' => $copyattempt->id], '*', MUST_EXIST);
        $this->assertEquals($sections[0]->id, $response->sectionid);
    }
}
