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

use mod_aiinteractivevideo\local\ai_generator;
use mod_aiinteractivevideo\local\transcript;
use mod_aiinteractivevideo\local\video;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/aiinteractivevideo/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Uploaded MP4 videos and uploaded transcript files.
 *
 * @package    mod_aiinteractivevideo
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aiinteractivevideo\local\video
 */
final class video_test extends \advanced_testcase {
    /** @var string A small WebVTT transcript. */
    private const VTT = "WEBVTT\n\n00:00:01.000 --> 00:00:05.000\nThe heart has four chambers.\n\n" .
        "00:00:06.000 --> 00:00:11.000\nThe right side pumps blood to the lungs.\n\n" .
        "00:00:12.000 --> 00:00:18.000\nThe left side pumps blood to the whole body.\n\n" .
        "00:00:19.000 --> 00:00:25.000\nValves stop the blood flowing backwards.\n";

    /**
     * Puts a file in a new draft area of the current user.
     *
     * @param string $filename
     * @param string $content
     * @return int draft item id
     */
    private function draft(string $filename, string $content): int {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
        return $draftid;
    }

    /**
     * Creates an activity that uses an uploaded MP4.
     *
     * @param \stdClass $course
     * @param string $content fake video bytes
     * @return \stdClass
     */
    private function upload_instance(\stdClass $course, string $content = 'fake-mp4-1'): \stdClass {
        return $this->getDataGenerator()->create_module('aiinteractivevideo', [
            'course' => $course->id,
            'videosource' => video::SOURCE_UPLOAD,
            'videourl' => 'https://www.youtube.com/watch?v=ruM4Xxhx32U',
            'videofile' => $this->draft('lesson.mp4', $content),
            'transcript' => self::VTT,
            'numinteractions' => 2,
        ]);
    }

    /**
     * Older activities and anything unknown count as YouTube.
     */
    public function test_source_defaults_to_youtube(): void {
        $this->assertEquals(video::SOURCE_YOUTUBE, video::source((object)[]));
        $this->assertEquals(video::SOURCE_YOUTUBE, video::source((object)['videosource' => 'vimeo']));
        $this->assertEquals(video::SOURCE_UPLOAD, video::source((object)['videosource' => 'upload']));
    }

    /**
     * A YouTube activity is unchanged: source youtube, no file, YouTube id kept.
     */
    public function test_youtube_activity_unchanged(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('aiinteractivevideo', ['course' => $course->id]);
        $record = $DB->get_record('aiinteractivevideo', ['id' => $instance->id]);
        $context = \context_module::instance($instance->cmid);
        $this->assertEquals('youtube', $record->videosource);
        $this->assertEquals('ruM4Xxhx32U', $record->videoid);
        $this->assertNull(video::file($context));
        $this->assertTrue(video::has_video($record, $context));
    }

    /**
     * An uploaded MP4 is stored in the activity, served through pluginfile, and the YouTube link is dropped.
     */
    public function test_upload_saved(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->upload_instance($course);
        $record = $DB->get_record('aiinteractivevideo', ['id' => $instance->id]);
        $context = \context_module::instance($instance->cmid);

        $this->assertEquals(video::SOURCE_UPLOAD, $record->videosource);
        $this->assertSame('', $record->videoid);
        $this->assertSame('', $record->videourl);
        $file = video::file($context);
        $this->assertNotNull($file);
        $this->assertEquals('lesson.mp4', $file->get_filename());
        $this->assertTrue(video::has_video($record, $context));
        $url = video::file_url($context);
        $this->assertStringContainsString('/pluginfile.php/' . $context->id . '/mod_aiinteractivevideo/video/0/lesson.mp4', $url);
        $this->assertEquals('upload:' . $file->get_contenthash(), video::signature($record, $context));
    }

    /**
     * Replacing the file, or switching back to YouTube, resets the stored length; switching removes the file.
     */
    public function test_update_changes_video(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->upload_instance($course);
        $cm = get_coursemodule_from_instance('aiinteractivevideo', $instance->id);
        $context = \context_module::instance($cm->id);
        $DB->set_field('aiinteractivevideo', 'videoduration', 120, ['id' => $instance->id]);

        $update = function (array $changes) use ($DB, $instance, $cm) {
            $data = $DB->get_record('aiinteractivevideo', ['id' => $instance->id]);
            $data->instance = $instance->id;
            $data->coursemodule = $cm->id;
            foreach ($changes as $key => $value) {
                $data->$key = $value;
            }
            aiinteractivevideo_update_instance($data);
            return $DB->get_record('aiinteractivevideo', ['id' => $instance->id]);
        };

        // The same file again keeps the length.
        $record = $update(['videosource' => 'upload', 'videofile' => $this->draft('lesson.mp4', 'fake-mp4-1')]);
        $this->assertEquals(120, (int)$record->videoduration);

        // A different file resets it.
        $record = $update(['videosource' => 'upload', 'videofile' => $this->draft('other.mp4', 'fake-mp4-2')]);
        $this->assertEquals(0, (int)$record->videoduration);
        $this->assertEquals('other.mp4', video::file($context)->get_filename());

        // Back to YouTube: the file is removed.
        $DB->set_field('aiinteractivevideo', 'videoduration', 90, ['id' => $instance->id]);
        $record = $update(['videosource' => 'youtube', 'videourl' => 'https://youtu.be/ruM4Xxhx32U']);
        $this->assertEquals('ruM4Xxhx32U', $record->videoid);
        $this->assertEquals(0, (int)$record->videoduration);
        $this->assertNull(video::file($context));
    }

    /**
     * The uploaded video and its source travel with a course backup.
     */
    public function test_backup_restore_keeps_video(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $this->upload_instance($course, 'fake-mp4-backup');

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $bc->get_results()['backup_destination']->extract_to_pathname(
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
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $copy = $DB->get_record('aiinteractivevideo', ['course' => $newcourseid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('aiinteractivevideo', $copy->id);
        $context = \context_module::instance($cm->id);
        $this->assertEquals(video::SOURCE_UPLOAD, $copy->videosource);
        $file = video::file($context);
        $this->assertNotNull($file);
        $this->assertEquals('fake-mp4-backup', $file->get_content());
        $this->assertTrue(video::has_video($copy, $context));
    }

    /**
     * An uploaded transcript file is read in any common encoding and parsed with its timings.
     */
    public function test_transcript_file(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertNull(video::draft_transcript(0));

        $text = video::draft_transcript($this->draft('captions.vtt', "\xEF\xBB\xBF" . self::VTT));
        $cues = transcript::parse($text);
        $this->assertCount(4, $cues);
        $this->assertEquals(6.0, $cues[1]['start']);
        $this->assertEquals('The right side pumps blood to the lungs.', $cues[1]['text']);

        $utf16 = "\xFF\xFE" . \core_text::convert("1\n00:00:02,000 --> 00:00:04,000\nCafé au lait\n", 'UTF-8', 'UTF-16LE');
        $cues = transcript::parse(video::draft_transcript($this->draft('captions.srt', $utf16)));
        $this->assertEquals('Café au lait', $cues[0]['text']);
        $this->assertEquals(2.0, $cues[0]['start']);

        $cp1252 = "Caf\xE9 is French for coffee. It is served hot.";
        $this->assertEquals('Café is French for coffee. It is served hot.', video::draft_transcript($this->draft('t.txt', $cp1252)));
    }

    /**
     * The form puts an uploaded transcript file in place of the pasted text.
     */
    public function test_form_uses_transcript_file(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/aiinteractivevideo/mod_form.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $reflection = new \ReflectionClass(\mod_aiinteractivevideo_mod_form::class);
        $form = $reflection->newInstanceWithoutConstructor();
        $data = (object)['transcript' => 'pasted', 'transcriptfile' => $this->draft('own.vtt', self::VTT)];
        $form->data_postprocessing($data);
        $this->assertEquals(self::VTT, $data->transcript);
        $this->assertObjectNotHasProperty('transcriptfile', $data);

        $data = (object)['transcript' => 'pasted', 'transcriptfile' => file_get_unused_draft_itemid()];
        $form->data_postprocessing($data);
        $this->assertEquals('pasted', $data->transcript);
    }

    /**
     * LMS Labs gets the same request fields for an uploaded video, with no YouTube id.
     */
    public function test_request_body_for_upload(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('lmslabs_siteid', 'site-1', 'mod_aiinteractivevideo');
        set_config('lmslabs_apikey', 'key-1', 'mod_aiinteractivevideo');
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->upload_instance($course);
        $record = $DB->get_record('aiinteractivevideo', ['id' => $instance->id]);
        $json = ai_generator::request_body($record, '0b6f5f3c-1a2b-4c3d-8e9f-001122334455');
        $this->assertStringContainsString('"videoId":""', $json);
        $this->assertStringContainsString('transcript of a video', $json);
        $this->assertStringNotContainsString('YouTube video', $json);
    }
}
