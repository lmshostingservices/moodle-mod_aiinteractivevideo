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
use mod_aiinteractivevideo\local\builtin_generator;
use mod_aiinteractivevideo\local\interaction;
use mod_aiinteractivevideo\local\segmenter;
use mod_aiinteractivevideo\local\text_analyser;
use mod_aiinteractivevideo\local\transcript;

/**
 * Tests for the transcript-driven generators (built-in and AI import).
 *
 * @package    mod_aiinteractivevideo
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aiinteractivevideo\local\builtin_generator
 * @covers     \mod_aiinteractivevideo\local\ai_generator
 * @covers     \mod_aiinteractivevideo\local\segmenter
 * @covers     \mod_aiinteractivevideo\local\text_analyser
 */
final class generator_test extends \advanced_testcase {
    /**
     * The real TED-Ed transcript.
     *
     * @return string
     */
    private function transcript(): string {
        return file_get_contents(__DIR__ . '/fixtures/transcript_youtube.txt');
    }

    /**
     * Sections are contiguous, cover the video and follow sentence starts.
     */
    public function test_segmenter(): void {
        $cues = transcript::parse($this->transcript());
        foreach ([1, 3, 5, 8, 12] as $count) {
            $parts = segmenter::split($cues, $count, 267);
            $this->assertCount($count, $parts);
            $this->assertEquals(0.0, $parts[0]['start']);
            $this->assertEquals(267.0, end($parts)['end']);
            foreach ($parts as $i => $part) {
                $this->assertGreaterThan($part['start'], $part['end']);
                if ($i > 0) {
                    $this->assertEquals($parts[$i - 1]['end'], $part['start']);
                }
                $this->assertNotEmpty($part['text']);
            }
        }
    }

    /**
     * The built-in generator builds valid, varied activities made from the transcript itself.
     */
    public function test_builtin_generator_uses_transcript(): void {
        $this->resetAfterTest();
        $raw = $this->transcript();
        $plain = \core_text::strtolower(implode(' ', array_column(transcript::parse($raw), 'text')));
        $sections = builtin_generator::generate($raw, 8, interaction::TYPES, 267);
        $this->assertCount(8, $sections);
        // A name split across two top terms is kept as written in the transcript.
        $this->assertEquals('Leonardo da Vinci', $sections[0]->title);
        $types = array_column($sections, 'type');
        $this->assertGreaterThanOrEqual(5, count(array_unique($types)));
        for ($i = 1; $i < count($types); $i++) {
            $this->assertNotEquals($types[$i - 1], $types[$i], 'No type twice in a row');
        }
        foreach ($sections as $s) {
            $content = interaction::normalise($s->type, json_decode($s->content, true));
            $this->assertNotNull($content, $s->type);
            $this->assertNotEmpty($s->title);
            $this->assertNotEmpty($s->feedbackwrong);
            // Each activity quotes or tests words from the transcript.
            $words = array_filter(
                text_analyser::content_words(interaction::to_text($s->type, $content)),
                fn($w) => strlen($w) > 5
            );
            $found = array_filter($words, fn($w) => strpos($plain, $w) !== false);
            $this->assertGreaterThan(0, count($found), $s->type . ': ' . interaction::to_text($s->type, $content));
        }
    }

    /**
     * Only allowed types are used.
     */
    public function test_builtin_respects_types(): void {
        $this->resetAfterTest();
        $sections = builtin_generator::generate($this->transcript(), 5, ['ordering', 'swipe'], 267);
        $this->assertCount(5, $sections);
        foreach ($sections as $s) {
            $this->assertContains($s->type, ['ordering', 'swipe']);
        }
    }

    /**
     * Keywords prefer the distinctive terms of each part.
     */
    public function test_keywords(): void {
        $docs = ['The aorta and the pulmonary artery rise from the ventricles. The aorta is large.',
            'Leonardo da Vinci gave up studying the heart.', 'Blood is opaque, blood vessels hide the valves.'];
        $keywords = text_analyser::keywords($docs, 5);
        $this->assertEquals('aorta', $keywords[0][0]);
        $this->assertContains('blood', $keywords[2]);
    }

    /**
     * The AI prompt contains the timed transcript and the rules.
     */
    public function test_prompt(): void {
        $instance = (object)['name' => 'Heart', 'transcript' => $this->transcript(), 'numinteractions' => 6,
            'types' => 'ordering,matching', 'videoduration' => 267];
        $prompt = ai_generator::prompt($instance);
        $this->assertStringContainsString('exactly 6 sections', $prompt);
        $this->assertStringContainsString('[0:06] For most of history', $prompt);
        $this->assertStringContainsString('- ordering:', $prompt);
        $this->assertStringNotContainsString('- swipe:', $prompt);
        $this->assertStringContainsString('ONLY on what is said', $prompt);
    }

    /**
     * An AI answer is imported; broken activities are rebuilt from the same stretch of transcript.
     */
    public function test_import(): void {
        $this->resetAfterTest();
        $instance = (object)['name' => 'Heart', 'transcript' => $this->transcript(), 'numinteractions' => 8,
            'types' => implode(',', interaction::TYPES), 'videoduration' => 267];
        $json = file_get_contents(__DIR__ . '/fixtures/ai_response.json');
        $fence = str_repeat(chr(96), 3);
        $sections = ai_generator::import($fence . "json\n" . $json . "\n" . $fence, $instance);
        $this->assertCount(8, $sections);
        $this->assertEquals('unscramble', $sections[0]->type);
        $this->assertEquals(25.0, $sections[0]->endtime);
        $this->assertEquals(267.0, end($sections)->endtime);
        $this->assertStringContainsString('Leonardo', $sections[0]->feedbackcorrect);

        // Break one activity: it is rebuilt with the built-in generator but keeps the AI title and times.
        $data = json_decode($json, true);
        $data['sections'][2]['text'] = 'no gaps at all';
        $sections = ai_generator::import(json_encode($data), $instance);
        $this->assertCount(8, $sections);
        $this->assertEquals('How the heart gets taught', $sections[2]->title);
        $this->assertNotNull(interaction::normalise($sections[2]->type, json_decode($sections[2]->content, true)));

        $this->assertEmpty(ai_generator::import('Sorry, I cannot help with that.', $instance));
    }

    /**
     * Without an AI provider the AI option is reported unavailable.
     */
    public function test_ai_unavailable_by_default(): void {
        $this->resetAfterTest();
        $this->assertFalse(ai_generator::available());
    }
}
