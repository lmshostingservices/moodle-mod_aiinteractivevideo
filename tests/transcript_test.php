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

use mod_aiinteractivevideo\local\transcript;
use mod_aiinteractivevideo\local\youtube;

/**
 * Tests for transcript parsing and YouTube links.
 *
 * @package    mod_aiinteractivevideo
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aiinteractivevideo\local\transcript
 * @covers     \mod_aiinteractivevideo\local\youtube
 */
final class transcript_test extends \basic_testcase {
    /**
     * The YouTube "Show transcript" copy format (timestamp on its own line).
     */
    public function test_youtube_copy_format(): void {
        $cues = transcript::parse(file_get_contents(__DIR__ . '/fixtures/transcript_youtube.txt'));
        $this->assertCount(93, $cues);
        $this->assertEquals(6.0, $cues[0]['start']);
        $this->assertEquals('For most of history,', $cues[0]['text']);
        $this->assertEquals(249.0, end($cues)['start']);
        $this->assertStringContainsString('the one you love', end($cues)['text']);
    }

    /**
     * Timestamps at the start of the line, accessibility duration lines and brackets.
     */
    public function test_inline_and_bracketed(): void {
        $raw = "0:00 Welcome to the lesson\n2 seconds\n[0:05] The heart has four chambers.\n(1:02:03) Much later on.";
        $cues = transcript::parse($raw);
        $this->assertCount(3, $cues);
        $this->assertEquals(5.0, $cues[1]['start']);
        $this->assertEquals('The heart has four chambers.', $cues[1]['text']);
        $this->assertEquals(3723.0, $cues[2]['start']);
    }

    /**
     * SRT and WebVTT captions, including rolling duplicates and tags.
     */
    public function test_srt_and_vtt(): void {
        $srt = "1\n00:00:01,000 --> 00:00:03,000\nHello <i>there</i>\n\n2\n00:00:03,500 --> 00:00:06,000\nSecond line\n";
        $cues = transcript::parse($srt);
        $this->assertCount(2, $cues);
        $this->assertEquals(1.0, $cues[0]['start']);
        $this->assertEquals('Hello there', $cues[0]['text']);
        $this->assertEquals(3.5, $cues[1]['start']);

        $vtt = "WEBVTT\n\n00:00.000 --> 00:02.000\nthe heart pumps\n\n00:02.000 --> 00:04.000\nthe heart pumps\n\n" .
            "00:04.000 --> 00:06.000\nthe heart pumps blood [Music]\n";
        $cues = transcript::parse($vtt);
        $this->assertCount(2, $cues);
        $this->assertEquals('blood', $cues[1]['text']);
    }

    /**
     * Text without timestamps is spread over the video.
     */
    public function test_untimed_text(): void {
        $cues = transcript::parse("First sentence here. Second sentence follows. Third one ends it.", 30);
        $this->assertCount(3, $cues);
        $this->assertEquals(0.0, $cues[0]['start']);
        $this->assertGreaterThan($cues[1]['start'], $cues[2]['start']);
        $this->assertLessThan(30, $cues[2]['start']);
    }

    /**
     * Clock formatting and parsing.
     */
    public function test_clock(): void {
        $this->assertEquals('4:27', transcript::clock(267));
        $this->assertEquals('1:02:03', transcript::clock(3723));
        $this->assertEquals(83.0, transcript::parse_clock('1:23'));
        $this->assertEquals(3723.0, transcript::parse_clock('01:02:03'));
        $this->assertEquals(12.5, transcript::parse_clock('12.5'));
        $this->assertNull(transcript::parse_clock('soon'));
    }

    /**
     * YouTube link formats.
     */
    public function test_video_id(): void {
        $id = 'ruM4Xxhx32U';
        foreach ([
            "https://www.youtube.com/watch?v=$id",
            "https://youtube.com/watch?v=$id&t=30s",
            "https://youtu.be/$id?si=abc",
            "https://www.youtube.com/embed/$id",
            "https://www.youtube.com/shorts/$id",
            "https://www.youtube-nocookie.com/embed/$id",
            "https://m.youtube.com/watch?v=$id",
            "youtube.com/live/$id",
            $id,
        ] as $url) {
            $this->assertEquals($id, youtube::video_id($url), $url);
        }
        $this->assertNull(youtube::video_id('https://vimeo.com/12345'));
        $this->assertNull(youtube::video_id('https://evil.example/watch?v=' . $id));
        $this->assertNull(youtube::video_id('https://www.youtube.com/watch?v=short'));
        $this->assertStringContainsString($id, youtube::thumbnail($id));
    }
}
