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

/**
 * Tests for interaction validation, authoring format, payloads, marking, hints and solutions.
 *
 * @package    mod_aiinteractivevideo
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aiinteractivevideo\local\interaction
 */
final class interaction_test extends \advanced_testcase {
    /**
     * One valid example per type, taken from the AI fixture.
     *
     * @return array type => content
     */
    private function examples(): array {
        $json = json_decode(file_get_contents(__DIR__ . '/fixtures/ai_response.json'), true);
        $out = [];
        foreach ($json['sections'] as $s) {
            $out[$s['type']] = interaction::normalise($s['type'], $s);
        }
        return $out;
    }

    /**
     * Section record for a type.
     *
     * @param string $type
     * @param array $content
     * @return \stdClass
     */
    private function section(string $type, array $content): \stdClass {
        return (object)['id' => 42, 'type' => $type, 'content' => json_encode($content)];
    }

    /**
     * Every type in the fixture is valid and round-trips through the authoring text format.
     */
    public function test_normalise_and_text_roundtrip(): void {
        $examples = $this->examples();
        $this->assertEqualsCanonicalizing(interaction::TYPES, array_keys($examples));
        foreach ($examples as $type => $content) {
            $this->assertNotNull($content, $type);
            $text = interaction::to_text($type, $content);
            $this->assertEquals($content, interaction::from_text($type, $text, $content['prompt']), $type);
        }
    }

    /**
     * Invalid content is rejected.
     */
    public function test_normalise_rejects_invalid(): void {
        $this->assertNull(interaction::normalise('cardselect', ['cards' => [['text' => 'A', 'correct' => true]]]));
        $this->assertNull(
            interaction::normalise(
                'cardselect',
                ['cards' => [['text' => 'A', 'correct' => true],
                ['text' => 'B', 'correct' => true], ['text' => 'C', 'correct' => true]]]
            )
        );
        $this->assertNull(interaction::normalise('fillblanks', ['text' => 'No gaps here']));
        $this->assertNull(interaction::normalise('spotmistake', ['text' => 'Two {{wrong words|right}} here']));
        $this->assertNull(interaction::normalise('ordering', ['items' => ['one', 'two']]));
        $this->assertNull(interaction::normalise('unscramble', ['answer' => 'ab']));
        $this->assertNull(interaction::normalise('nosuchtype', []));
        // Tags are stripped from text.
        $clean = interaction::normalise('ordering', ['items' => ['<b>one</b>', 'two', 'three<script>x</script>']]);
        $this->assertEquals(['one', 'two', 'threex'], $clean['items']);
    }

    /**
     * Payloads never contain answers, and the correct response marks as correct for every type.
     */
    public function test_payload_and_marking(): void {
        foreach ($this->examples() as $type => $content) {
            $section = $this->section($type, $content);
            $payload = interaction::payload($section, 'salt1');
            $encoded = json_encode($payload);
            $this->assertStringNotContainsString('"correct"', $encoded, $type);
            $this->assertStringNotContainsString('"fact"', $encoded, $type);
            $this->assertStringNotContainsString('[[', $encoded, $type);
            $this->assertStringNotContainsString('{{', $encoded, $type);
            $solution = interaction::solution($section, 'salt1');
            $response = array_map(fn($p) => ['key' => $p['key'], 'value' => $p['value']], $solution);
            $mark = interaction::mark($section, 'salt1', $response);
            $this->assertTrue($mark['correct'], $type);
            $this->assertEquals(0, $mark['missing'], $type);
            // An empty response is never correct.
            $this->assertFalse(interaction::mark($section, 'salt1', [])['correct'], $type);
            // Tokens depend on the attempt salt.
            $this->assertNotEquals($solution, interaction::solution($section, 'salt2'), $type);
        }
    }

    /**
     * Wrong answers are marked item by item.
     */
    public function test_partial_marking(): void {
        $content = $this->examples()['cardselect'];
        $section = $this->section('cardselect', $content);
        $payload = interaction::payload($section, 's');
        $this->assertEquals(3, $payload['selectcount']);
        $solution = interaction::solution($section, 's');
        $right = array_column($solution, 'key');
        $wrong = array_values(array_diff(array_column($payload['items'], 'token'), $right));
        $mark = interaction::mark($section, 's', [['key' => $right[0], 'value' => '1'], ['key' => $wrong[0], 'value' => '1']]);
        $this->assertFalse($mark['correct']);
        $this->assertEquals(2, $mark['missing']);
        $bykey = array_column($mark['results'], 'correct', 'key');
        $this->assertEquals(1, $bykey[$right[0]]);
        $this->assertEquals(0, $bykey[$wrong[0]]);

        // Ordering: swapping two items marks those two positions wrong.
        $section = $this->section('ordering', $this->examples()['ordering']);
        $solution = interaction::solution($section, 's');
        $response = array_map(fn($p) => ['key' => $p['key'], 'value' => $p['value']], $solution);
        [$response[0]['value'], $response[1]['value']] = [$response[1]['value'], $response[0]['value']];
        $mark = interaction::mark($section, 's', $response);
        $this->assertFalse($mark['correct']);
        $this->assertEquals([0, 0, 1, 1, 1], array_column($mark['results'], 'correct'));
    }

    /**
     * Repeated letters and repeated gap words are interchangeable.
     */
    public function test_interchangeable_tokens(): void {
        $section = $this->section('unscramble', ['prompt' => 'Clue', 'answer' => 'LEVEL']);
        $payload = interaction::payload($section, 's');
        $bytext = [];
        foreach ($payload['items'] as $item) {
            $bytext[$item['text']][] = $item['token'];
        }
        // Use the second L first and the first L last.
        $response = [
            ['key' => 'p0', 'value' => $bytext['L'][1]], ['key' => 'p1', 'value' => $bytext['E'][0]],
            ['key' => 'p2', 'value' => $bytext['V'][0]], ['key' => 'p3', 'value' => $bytext['E'][1]],
            ['key' => 'p4', 'value' => $bytext['L'][0]],
        ];
        $this->assertTrue(interaction::mark($section, 's', $response)['correct']);
        $this->assertEquals('5', $payload['wordlengths']);

        $section = $this->section(
            'fillblanks',
            ['prompt' => 'P', 'text' => 'The [[heart]] and the [[heart]] again',
            'distractors' => ['lung']]
        );
        $payload = interaction::payload($section, 's');
        $this->assertCount(2, $payload['items']);
        $heart = array_values(array_filter($payload['items'], fn($i) => $i['text'] === 'heart'))[0]['token'];
        $gaps = array_values(array_filter($payload['segments'], fn($s) => $s['blank']));
        $mark = interaction::mark(
            $section,
            's',
            [['key' => $gaps[0]['token'], 'value' => $heart],
            ['key' => $gaps[1]['token'], 'value' => $heart]]
        );
        $this->assertTrue($mark['correct']);
    }

    /**
     * Hints help without giving away the whole answer.
     */
    public function test_assist(): void {
        foreach ($this->examples() as $type => $content) {
            $section = $this->section($type, $content);
            $solution = interaction::solution($section, 's');
            $max = interaction::max_hints($section, 's');
            $this->assertGreaterThan(0, $max, $type);
            $assist = interaction::assist($section, 's', 99);
            if (in_array($type, ['cardselect', 'spotmistake'], true)) {
                // Only wrong choices are ruled out.
                $this->assertEmpty(array_intersect(array_column($assist, 'key'), array_column($solution, 'key')), $type);
            } else {
                $this->assertLessThan(count($solution), count($assist), $type);
            }
        }
    }
}
