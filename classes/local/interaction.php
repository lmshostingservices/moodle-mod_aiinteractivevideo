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

use stdClass;

/**
 * Interaction types: validation, authoring text format, learner payloads, marking, hints and solutions.
 *
 * The stored content (JSON) contains the answer key and never leaves the server. Learners receive a payload
 * with per-attempt random tokens, and every response is marked here.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class interaction {
    /** @var string[] All interaction types, in the order they are offered. */
    public const TYPES = ['cardselect', 'categorize', 'fillblanks', 'ordering', 'matching', 'spotmistake', 'swipe',
        'unscramble'];

    /** @var int Maximum length of any single text in an interaction. */
    private const MAXTEXT = 400;

    /**
     * Cleans one piece of plain text.
     *
     * @param mixed $value
     * @param int $max
     * @return string
     */
    public static function clean($value, int $max = self::MAXTEXT): string {
        if (!is_scalar($value)) {
            return '';
        }
        $value = clean_param((string)$value, PARAM_TEXT);
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        return \core_text::substr($value, 0, $max);
    }

    /**
     * Validates and normalises interaction content.
     *
     * @param string $type
     * @param mixed $content decoded JSON (array)
     * @return array|null canonical content, or null when invalid
     */
    public static function normalise(string $type, $content): ?array {
        if (!is_array($content) || !in_array($type, self::TYPES, true)) {
            return null;
        }
        $prompt = self::clean($content['prompt'] ?? '');
        switch ($type) {
            case 'cardselect':
                $cards = [];
                foreach (($content['cards'] ?? []) as $card) {
                    $text = self::clean($card['text'] ?? '', 160);
                    if ($text !== '') {
                        $cards[] = ['text' => $text, 'correct' => !empty($card['correct'])];
                    }
                }
                $cards = self::unique_by($cards, 'text');
                $right = count(array_filter($cards, fn($c) => $c['correct']));
                if (count($cards) < 3 || count($cards) > 9 || $right < 1 || $right === count($cards)) {
                    return null;
                }
                return ['prompt' => $prompt, 'cards' => $cards];

            case 'categorize':
                $categories = [];
                $seen = [];
                foreach (($content['categories'] ?? []) as $cat) {
                    $name = self::clean($cat['name'] ?? '', 60);
                    $items = [];
                    foreach (($cat['items'] ?? []) as $item) {
                        $item = self::clean($item, 120);
                        $key = \core_text::strtolower($item);
                        if ($item !== '' && !isset($seen[$key])) {
                            $items[] = $item;
                            $seen[$key] = true;
                        }
                    }
                    if ($name !== '' && $items) {
                        $categories[] = ['name' => $name, 'items' => $items];
                    }
                }
                $total = array_sum(array_map(fn($c) => count($c['items']), $categories));
                if (count($categories) < 2 || count($categories) > 4 || $total < 3 || $total > 14) {
                    return null;
                }
                return ['prompt' => $prompt, 'categories' => $categories];

            case 'fillblanks':
                $text = self::clean($content['text'] ?? '', 900);
                preg_match_all('/\[\[([^\[\]]{1,60})\]\]/u', $text, $m);
                $blanks = count($m[1]);
                if ($blanks < 1 || $blanks > 6) {
                    return null;
                }
                $answers = array_map(fn($a) => \core_text::strtolower(trim($a)), $m[1]);
                $distractors = [];
                foreach (($content['distractors'] ?? []) as $d) {
                    $d = self::clean($d, 60);
                    if ($d !== '' && !in_array(\core_text::strtolower($d), $answers, true)) {
                        $distractors[] = $d;
                    }
                }
                $distractors = array_slice(array_values(array_unique($distractors)), 0, 6);
                return ['prompt' => $prompt, 'text' => $text, 'distractors' => $distractors];

            case 'ordering':
                $items = [];
                foreach (($content['items'] ?? []) as $item) {
                    $item = self::clean($item, 160);
                    if ($item !== '') {
                        $items[] = $item;
                    }
                }
                $items = array_values(array_unique($items));
                if (count($items) < 3 || count($items) > 7) {
                    return null;
                }
                return ['prompt' => $prompt, 'items' => $items];

            case 'matching':
                $pairs = [];
                foreach (($content['pairs'] ?? []) as $pair) {
                    $left = self::clean($pair['left'] ?? '', 80);
                    $right = self::clean($pair['right'] ?? '', 160);
                    if ($left !== '' && $right !== '') {
                        $pairs[] = ['left' => $left, 'right' => $right];
                    }
                }
                $pairs = self::unique_by(self::unique_by($pairs, 'left'), 'right');
                if (count($pairs) < 2 || count($pairs) > 6) {
                    return null;
                }
                return ['prompt' => $prompt, 'pairs' => $pairs];

            case 'spotmistake':
                $text = self::clean($content['text'] ?? '', 900);
                preg_match_all('/\{\{([^{}|]{1,40})\|([^{}|]{1,40})\}\}/u', $text, $m);
                $count = count($m[0]);
                if ($count < 1 || $count > 3) {
                    return null;
                }
                // Each mistake must be a single word so it can be tapped.
                foreach ($m[1] as $wrong) {
                    if (preg_match('/\s/u', trim($wrong))) {
                        return null;
                    }
                }
                return ['prompt' => $prompt, 'text' => $text];

            case 'swipe':
                $statements = [];
                foreach (($content['statements'] ?? []) as $s) {
                    $text = self::clean($s['text'] ?? '', 200);
                    if ($text !== '') {
                        $statements[] = ['text' => $text, 'fact' => !empty($s['fact'])];
                    }
                }
                $statements = self::unique_by($statements, 'text');
                if (count($statements) < 2 || count($statements) > 8) {
                    return null;
                }
                return ['prompt' => $prompt, 'statements' => $statements];

            case 'unscramble':
                $answer = \core_text::strtoupper(self::clean($content['answer'] ?? '', 30));
                $answer = trim(preg_replace('/[^\p{L}\p{N} -]/u', '', $answer));
                $letters = preg_replace('/[\s-]/u', '', $answer);
                if (\core_text::strlen($letters) < 3 || \core_text::strlen($letters) > 20) {
                    return null;
                }
                return ['prompt' => $prompt, 'answer' => $answer];
        }
        return null;
    }

    /**
     * Removes entries with a duplicate (case-insensitive) field.
     *
     * @param array $rows
     * @param string $field
     * @return array
     */
    private static function unique_by(array $rows, string $field): array {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $key = \core_text::strtolower($row[$field]);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Converts content to the teacher authoring text format.
     *
     * @param string $type
     * @param array $content
     * @return string
     */
    public static function to_text(string $type, array $content): string {
        $lines = [];
        switch ($type) {
            case 'cardselect':
                foreach ($content['cards'] as $c) {
                    $lines[] = ($c['correct'] ? '* ' : '') . $c['text'];
                }
                break;
            case 'categorize':
                foreach ($content['categories'] as $c) {
                    $lines[] = $c['name'] . ': ' . implode('; ', $c['items']);
                }
                break;
            case 'fillblanks':
                $lines[] = $content['text'];
                if ($content['distractors']) {
                    $lines[] = '';
                    $lines[] = '- ' . implode('; ', $content['distractors']);
                }
                break;
            case 'ordering':
                $lines = $content['items'];
                break;
            case 'matching':
                foreach ($content['pairs'] as $p) {
                    $lines[] = $p['left'] . ' = ' . $p['right'];
                }
                break;
            case 'spotmistake':
                $lines[] = $content['text'];
                break;
            case 'swipe':
                foreach ($content['statements'] as $s) {
                    $lines[] = ($s['fact'] ? 'T: ' : 'F: ') . $s['text'];
                }
                break;
            case 'unscramble':
                $lines[] = $content['answer'];
                break;
        }
        return implode("\n", $lines);
    }

    /**
     * Parses the teacher authoring text format.
     *
     * @param string $type
     * @param string $text
     * @param string $prompt
     * @return array|null canonical content or null when invalid
     */
    public static function from_text(string $type, string $text, string $prompt): ?array {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text)), fn($l) => $l !== ''));
        $content = ['prompt' => $prompt];
        switch ($type) {
            case 'cardselect':
                $content['cards'] = array_map(fn($l) => [
                    'text' => ltrim(preg_replace('/^\*\s*/u', '', $l)),
                    'correct' => str_starts_with($l, '*'),
                ], $lines);
                break;
            case 'categorize':
                $content['categories'] = [];
                foreach ($lines as $l) {
                    if (strpos($l, ':') === false) {
                        continue;
                    }
                    [$name, $items] = explode(':', $l, 2);
                    $content['categories'][] = ['name' => trim($name), 'items' => array_map('trim', explode(';', $items))];
                }
                break;
            case 'fillblanks':
                $passage = [];
                $content['distractors'] = [];
                foreach ($lines as $l) {
                    if (str_starts_with($l, '-')) {
                        $content['distractors'] = array_merge(
                            $content['distractors'],
                            array_map('trim', explode(';', ltrim($l, '- ')))
                        );
                    } else {
                        $passage[] = $l;
                    }
                }
                $content['text'] = implode(' ', $passage);
                break;
            case 'ordering':
                $content['items'] = array_map(fn($l) => preg_replace('/^\d+[.)]\s*/u', '', $l), $lines);
                break;
            case 'matching':
                $content['pairs'] = [];
                foreach ($lines as $l) {
                    if (strpos($l, '=') !== false) {
                        [$left, $right] = explode('=', $l, 2);
                        $content['pairs'][] = ['left' => trim($left), 'right' => trim($right)];
                    }
                }
                break;
            case 'spotmistake':
                $content['text'] = implode(' ', $lines);
                break;
            case 'swipe':
                $content['statements'] = [];
                foreach ($lines as $l) {
                    if (preg_match('/^([TF])\s*:\s*(.+)$/iu', $l, $m)) {
                        $content['statements'][] = ['text' => $m[2], 'fact' => strtoupper($m[1]) === 'T'];
                    }
                }
                break;
            case 'unscramble':
                $content['answer'] = $lines[0] ?? '';
                break;
        }
        return self::normalise($type, $content);
    }

    /**
     * Deterministic per-attempt token.
     *
     * @param string $salt attempt salt
     * @param int $sectionid
     * @param string $kind
     * @param int|string $index
     * @return string
     */
    public static function token(string $salt, int $sectionid, string $kind, $index): string {
        return substr(hash('sha256', $salt . '|' . $sectionid . '|' . $kind . '|' . $index), 0, 12);
    }

    /**
     * Sorts rows by their token: a stable shuffle for this attempt.
     *
     * @param array $rows rows with a 'token' key
     * @return array
     */
    private static function shuffled(array $rows): array {
        usort($rows, fn($a, $b) => strcmp($a['token'], $b['token']));
        return $rows;
    }

    /**
     * Splits fill-in-the-blanks text into segments.
     *
     * @param string $text
     * @return array list of ['blank' => bool, 'text' => string]
     */
    private static function blank_segments(string $text): array {
        $parts = preg_split('/(\[\[[^\[\]]{1,60}\]\])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $part) {
            if (preg_match('/^\[\[(.+)\]\]$/u', $part, $m)) {
                $out[] = ['blank' => true, 'text' => trim($m[1])];
            } else {
                $out[] = ['blank' => false, 'text' => $part];
            }
        }
        return $out;
    }

    /**
     * Splits spot-the-mistake text into tappable words.
     *
     * @param string $text
     * @return array list of ['text' => shown word, 'mistake' => bool, 'correction' => string]
     */
    private static function mistake_words(string $text): array {
        $parts = preg_split('/(\{\{[^{}|]{1,40}\|[^{}|]{1,40}\}\})/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $words = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{\{([^{}|]+)\|([^{}|]+)\}\}$/u', $part, $m)) {
                $words[] = ['text' => trim($m[1]), 'mistake' => true, 'correction' => trim($m[2])];
                continue;
            }
            foreach (preg_split('/\s+/u', trim($part)) as $w) {
                if ($w === '') {
                    continue;
                }
                // Punctuation straight after a mistake belongs to the previous word.
                if ($words && preg_match('/^[.,;:!?…)"”]+$/u', $w)) {
                    $words[count($words) - 1]['text'] .= $w;
                    continue;
                }
                $words[] = ['text' => $w, 'mistake' => false, 'correction' => ''];
            }
        }
        return $words;
    }

    /**
     * Letters of an unscramble answer.
     *
     * @param string $answer
     * @return array ['letters' => string[], 'lengths' => int[]]
     */
    private static function letters(string $answer): array {
        $letters = [];
        $lengths = [];
        foreach (preg_split('/[\s-]+/u', $answer) as $word) {
            $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
            if ($chars) {
                $lengths[] = count($chars);
                $letters = array_merge($letters, $chars);
            }
        }
        return ['letters' => $letters, 'lengths' => $lengths];
    }

    /**
     * Decodes a section's stored content.
     *
     * @param stdClass $section
     * @return array
     */
    public static function content(stdClass $section): array {
        $content = json_decode((string)$section->content, true);
        return self::normalise($section->type, $content) ?? [];
    }

    /**
     * Builds the learner payload (no answers).
     *
     * @param stdClass $section
     * @param string $salt
     * @return array
     */
    public static function payload(stdClass $section, string $salt): array {
        $c = self::content($section);
        $sid = (int)$section->id;
        $tok = fn($kind, $i) => self::token($salt, $sid, $kind, $i);
        $payload = ['prompt' => $c['prompt'] ?? '', 'selectcount' => 0, 'wordlengths' => '', 'items' => [],
            'groups' => [], 'segments' => []];
        switch ($section->type) {
            case 'cardselect':
                foreach ($c['cards'] as $i => $card) {
                    $payload['items'][] = ['token' => $tok('card', $i), 'text' => $card['text']];
                }
                $payload['items'] = self::shuffled($payload['items']);
                $payload['selectcount'] = count(array_filter($c['cards'], fn($x) => $x['correct']));
                break;
            case 'categorize':
                $i = 0;
                foreach ($c['categories'] as $g => $cat) {
                    $payload['groups'][] = ['token' => $tok('group', $g), 'text' => $cat['name']];
                    foreach ($cat['items'] as $item) {
                        $payload['items'][] = ['token' => $tok('item', $i++), 'text' => $item];
                    }
                }
                $payload['items'] = self::shuffled($payload['items']);
                break;
            case 'fillblanks':
                $b = 0;
                $bank = [];
                foreach (self::blank_segments($c['text']) as $seg) {
                    if ($seg['blank']) {
                        $payload['segments'][] = ['text' => '', 'token' => $tok('blank', $b), 'blank' => 1];
                        $bank[\core_text::strtolower($seg['text'])] = $seg['text'];
                        $b++;
                    } else {
                        $payload['segments'][] = ['text' => $seg['text'], 'token' => '', 'blank' => 0];
                    }
                }
                foreach ($c['distractors'] as $d) {
                    $bank[\core_text::strtolower($d)] = $bank[\core_text::strtolower($d)] ?? $d;
                }
                foreach (array_values($bank) as $i => $word) {
                    $payload['items'][] = ['token' => $tok('word', \core_text::strtolower($word)), 'text' => $word];
                }
                $payload['items'] = self::shuffled($payload['items']);
                break;
            case 'ordering':
                foreach ($c['items'] as $i => $item) {
                    $payload['items'][] = ['token' => $tok('step', $i), 'text' => $item];
                }
                $shuffled = self::shuffled($payload['items']);
                if (array_column($shuffled, 'token') === array_column($payload['items'], 'token')) {
                    $shuffled = array_merge(array_slice($shuffled, 1), array_slice($shuffled, 0, 1));
                }
                $payload['items'] = $shuffled;
                break;
            case 'matching':
                foreach ($c['pairs'] as $i => $pair) {
                    $payload['items'][] = ['token' => $tok('left', $i), 'text' => $pair['left']];
                    $payload['groups'][] = ['token' => $tok('right', $i), 'text' => $pair['right']];
                }
                $payload['items'] = self::shuffled($payload['items']);
                $payload['groups'] = self::shuffled($payload['groups']);
                break;
            case 'spotmistake':
                $words = self::mistake_words($c['text']);
                foreach ($words as $i => $w) {
                    $payload['segments'][] = ['text' => $w['text'], 'token' => $tok('word', $i), 'blank' => 0];
                }
                $payload['selectcount'] = count(array_filter($words, fn($w) => $w['mistake']));
                break;
            case 'swipe':
                foreach ($c['statements'] as $i => $s) {
                    $payload['items'][] = ['token' => $tok('statement', $i), 'text' => $s['text']];
                }
                $payload['items'] = self::shuffled($payload['items']);
                break;
            case 'unscramble':
                $data = self::letters($c['answer']);
                foreach ($data['letters'] as $i => $letter) {
                    $payload['items'][] = ['token' => $tok('letter', $i), 'text' => $letter];
                }
                $shuffled = self::shuffled($payload['items']);
                if (implode('', array_column($shuffled, 'text')) === implode('', $data['letters'])) {
                    $shuffled = array_reverse($shuffled);
                }
                $payload['items'] = $shuffled;
                $payload['wordlengths'] = implode(',', $data['lengths']);
                break;
        }
        return $payload;
    }

    /**
     * The full answer, as key/value pairs in the same shape as a learner response.
     *
     * @param stdClass $section
     * @param string $salt
     * @return array list of ['key' => string, 'value' => string, 'text' => string]
     */
    public static function solution(stdClass $section, string $salt): array {
        $c = self::content($section);
        $sid = (int)$section->id;
        $tok = fn($kind, $i) => self::token($salt, $sid, $kind, $i);
        $out = [];
        switch ($section->type) {
            case 'cardselect':
                foreach ($c['cards'] as $i => $card) {
                    if ($card['correct']) {
                        $out[] = ['key' => $tok('card', $i), 'value' => '1', 'text' => ''];
                    }
                }
                break;
            case 'categorize':
                $i = 0;
                foreach ($c['categories'] as $g => $cat) {
                    foreach ($cat['items'] as $item) {
                        $out[] = ['key' => $tok('item', $i++), 'value' => $tok('group', $g), 'text' => ''];
                    }
                }
                break;
            case 'fillblanks':
                $b = 0;
                foreach (self::blank_segments($c['text']) as $seg) {
                    if ($seg['blank']) {
                        $out[] = ['key' => $tok('blank', $b++), 'value' => $tok('word', \core_text::strtolower($seg['text'])),
                            'text' => $seg['text']];
                    }
                }
                break;
            case 'ordering':
                foreach ($c['items'] as $i => $item) {
                    $out[] = ['key' => 'p' . $i, 'value' => $tok('step', $i), 'text' => ''];
                }
                break;
            case 'matching':
                foreach ($c['pairs'] as $i => $pair) {
                    $out[] = ['key' => $tok('left', $i), 'value' => $tok('right', $i), 'text' => ''];
                }
                break;
            case 'spotmistake':
                foreach (self::mistake_words($c['text']) as $i => $w) {
                    if ($w['mistake']) {
                        $out[] = ['key' => $tok('word', $i), 'value' => '1', 'text' => $w['correction']];
                    }
                }
                break;
            case 'swipe':
                foreach ($c['statements'] as $i => $s) {
                    $out[] = ['key' => $tok('statement', $i), 'value' => $s['fact'] ? 'fact' : 'fiction', 'text' => ''];
                }
                break;
            case 'unscramble':
                $data = self::letters($c['answer']);
                // Identical letters are interchangeable: map each slot to the first unused token of that letter.
                foreach ($data['letters'] as $i => $letter) {
                    $out[] = ['key' => 'p' . $i, 'value' => $tok('letter', $i), 'text' => $letter];
                }
                break;
        }
        return $out;
    }

    /**
     * Marks a response.
     *
     * @param stdClass $section
     * @param string $salt
     * @param array $response list of ['key' => string, 'value' => string]
     * @return array ['correct' => bool, 'results' => list of [key, value, correct, text], 'missing' => int]
     */
    public static function mark(stdClass $section, string $salt, array $response): array {
        $given = [];
        foreach ($response as $r) {
            $key = (string)($r['key'] ?? '');
            if ($key !== '') {
                $given[$key] = (string)($r['value'] ?? '');
            }
        }
        $solution = self::solution($section, $salt);
        $expected = [];
        foreach ($solution as $s) {
            $expected[$s['key']] = $s;
        }
        $results = [];
        $missing = 0;
        $allcorrect = true;
        $type = $section->type;

        if ($type === 'cardselect' || $type === 'spotmistake') {
            // Selection types: every selected token is right or wrong; unselected answers are "missing".
            foreach ($given as $key => $value) {
                if ($value !== '1') {
                    continue;
                }
                $ok = isset($expected[$key]);
                $results[] = ['key' => $key, 'value' => '1', 'correct' => $ok ? 1 : 0,
                    'text' => $ok ? $expected[$key]['text'] : ''];
                $allcorrect = $allcorrect && $ok;
            }
            foreach ($expected as $key => $s) {
                if (($given[$key] ?? '') !== '1') {
                    $missing++;
                }
            }
            $allcorrect = $allcorrect && $missing === 0;
        } else if ($type === 'unscramble') {
            // Compare letters, not tokens, so repeated letters are interchangeable.
            $letters = [];
            foreach (self::payload($section, $salt)['items'] as $item) {
                $letters[$item['token']] = $item['text'];
            }
            foreach ($expected as $key => $s) {
                $value = $given[$key] ?? '';
                $ok = $value !== '' && isset($letters[$value]) && $letters[$value] === $s['text'];
                $results[] = ['key' => $key, 'value' => $value, 'correct' => $ok ? 1 : 0, 'text' => ''];
                $allcorrect = $allcorrect && $ok;
                if ($value === '') {
                    $missing++;
                }
            }
        } else {
            foreach ($expected as $key => $s) {
                if (!array_key_exists($key, $given) || $given[$key] === '') {
                    $missing++;
                    $allcorrect = false;
                    continue;
                }
                $ok = hash_equals($s['value'], $given[$key]);
                if ($type === 'fillblanks' && !$ok) {
                    // Two blanks may share the same word; accept any token for identical text.
                    $ok = $given[$key] === self::token($salt, (int)$section->id, 'word', \core_text::strtolower($s['text']));
                }
                $results[] = ['key' => $key, 'value' => $given[$key], 'correct' => $ok ? 1 : 0, 'text' => ''];
                $allcorrect = $allcorrect && $ok;
            }
        }
        return ['correct' => $allcorrect, 'results' => $results, 'missing' => $missing];
    }

    /**
     * Returns the assist for hint number $n (1-based) in learn mode.
     *
     * @param stdClass $section
     * @param string $salt
     * @param int $n
     * @return array list of ['key' => string, 'value' => string, 'text' => string]
     */
    public static function assist(stdClass $section, string $salt, int $n): array {
        $solution = self::solution($section, $salt);
        $type = $section->type;
        if ($type === 'cardselect' || $type === 'spotmistake') {
            // Rule out wrong choices: a third of them per hint (at least one), never the answers.
            $right = array_column($solution, 'key');
            $payload = self::payload($section, $salt);
            $pool = $type === 'cardselect' ? $payload['items'] : $payload['segments'];
            $wrong = array_values(array_filter(array_column($pool, 'token'), fn($t) => !in_array($t, $right, true)));
            usort($wrong, fn($a, $b) => strcmp(hash('sha256', $salt . $a), hash('sha256', $salt . $b)));
            $per = max(1, (int)ceil(count($wrong) / 3));
            $keep = $type === 'cardselect' ? 1 : 3;
            $take = min(count($wrong) - $keep, $per * $n);
            return array_map(fn($t) => ['key' => $t, 'value' => 'eliminate', 'text' => ''], array_slice($wrong, 0, max(0, $take)));
        }
        // Everything else: reveal one more piece of the answer per hint, but never the whole answer.
        $limit = max(0, min($n, count($solution) - 1));
        return array_slice($solution, 0, $limit);
    }

    /**
     * Number of hints that still add something.
     *
     * @param stdClass $section
     * @param string $salt
     * @return int
     */
    public static function max_hints(stdClass $section, string $salt): int {
        if (in_array($section->type, ['cardselect', 'spotmistake'], true)) {
            return 3;
        }
        return max(0, count(self::solution($section, $salt)) - 1);
    }
}
