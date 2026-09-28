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

/**
 * AI generator: asks an AI model to divide the transcript into sections and write one interaction per section.
 *
 * The generation runs on LMS Labs AI (see {@see lmslabs}), which charges the site's LMS Labs AI credits. The answer is
 * checked and converted into interactions by {@see self::import()}.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_generator {
    /** @var int Transcript characters sent to the model. */
    private const MAXCHARS = 60000;

    /**
     * Whether AI generation can run on this site (LMS Labs credentials are set).
     *
     * @return bool
     */
    public static function available(): bool {
        return lmslabs::configured();
    }

    /**
     * Transcript lines with timestamps, compacted for the model.
     *
     * @param string $transcriptraw
     * @param int $duration
     * @return string
     */
    public static function timed_transcript(string $transcriptraw, int $duration = 0): string {
        $units = segmenter::units(transcript::parse($transcriptraw, $duration));
        $lines = [];
        $total = 0;
        foreach ($units as $unit) {
            $line = '[' . transcript::clock($unit['start']) . '] ' . $unit['text'];
            $total += strlen($line) + 1;
            if ($total > self::MAXCHARS) {
                break;
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * Builds the generation prompt.
     *
     * @param \stdClass $instance activity record (transcript, numinteractions, types, videoduration, name)
     * @return string
     */
    public static function prompt(\stdClass $instance): string {
        $types = array_values(array_intersect(interaction::TYPES, explode(',', (string)$instance->types)))
            ?: interaction::TYPES;
        $cues = transcript::parse((string)$instance->transcript, (int)$instance->videoduration);
        $end = $instance->videoduration > 0 ? (float)$instance->videoduration : transcript::estimated_end($cues);
        $schemas = [
            'cardselect' => '"cards": [{"text": "short card text", "correct": true}, ...]  (4-6 cards, 2-3 correct)',
            'categorize' => '"categories": [{"name": "category", "items": ["item", ...]}, ...]  (2-3 categories, ' .
                '6-9 items in total)',
            'fillblanks' => '"text": "A quote or close paraphrase with [[answer]] words in double square brackets", ' .
                '"distractors": ["plausible wrong word", ...]  (2-4 blanks, 2-3 distractors)',
            'ordering' => '"items": ["first step", "second step", ...]  (4-6 items in the CORRECT order)',
            'matching' => '"pairs": [{"left": "term", "right": "what it means or does"}, ...]  (3-5 pairs)',
            'spotmistake' => '"text": "A statement from the section where 1-2 single words are wrong, written as ' .
                '{{wrongword|correctword}}"',
            'swipe' => '"statements": [{"text": "statement", "fact": true}, ...]  (4-6 statements, mix of true and false)',
            'unscramble' => '"answer": "KEYTERM"  (one key term of 5-12 letters; the prompt is a clue for it)',
        ];
        $typelines = [];
        foreach ($types as $type) {
            $typelines[] = '- ' . $type . ': ' . $schemas[$type];
        }
        $count = max(1, min(20, (int)$instance->numinteractions));
        $lines = [
            'You are an expert instructional designer who creates engaging interactive video lessons.',
            '',
            'Below is the timestamped transcript of a YouTube video' .
                ($instance->name ? ' titled "' . $instance->name . '"' : '') . '. The video is about ' .
                transcript::clock($end) . ' long.',
            'Divide the video into exactly ' . $count . ' sections that follow the real topic changes in the ' .
                'transcript, and write ONE interactive activity for each section.',
            '',
            'Rules for sections:',
            '- Sections are consecutive and cover the whole video. The first starts at 0:00. Each section ends where ' .
                'the next begins; the last ends at ' . transcript::clock($end) . '.',
            '- Put each boundary exactly at a transcript timestamp where a new idea starts, so the video pauses at the ' .
                'end of a complete thought.',
            '- Give each section a short, specific title (2-6 words) taken from its content.',
            '',
            'Rules for activities (most important):',
            '- Base every activity ONLY on what is said in that section of the transcript. Use the speaker\'s own ' .
                'terms, facts, numbers, steps and examples. Do not use outside knowledge.',
            '- Test understanding of the key ideas of the section, not trivia or filler words.',
            '- Wrong options must be plausible but clearly wrong according to the transcript.',
            '- Use a variety of activity types and choose the type that best fits each section\'s content ' .
                '(steps or a process -> ordering; terms and meanings -> matching; groups or comparisons -> ' .
                'categorize; key facts -> fillblanks, swipe or cardselect; a key term -> unscramble; a claim -> ' .
                'spotmistake). Do not use the same type twice in a row.',
            '- Keep texts short enough to read on a phone: cards and items under 12 words, statements under 20 words.',
            '- "prompt" is the instruction the learner sees (one sentence).',
            '- "hint" nudges the learner towards the answer without giving it away.',
            '- "feedback_correct" explains why the answer is right, referring to what the video said (1-2 sentences).',
            '- "feedback_wrong" tells the learner what to listen for when the section replays (1 sentence).',
            '',
            'Allowed activity types and their fields:',
            implode("\n", $typelines),
            '',
            'Answer with JSON only (no markdown, no commentary) in exactly this shape:',
            '{"sections": [{"title": "...", "start": "0:00", "end": "1:23", "type": "fillblanks", "prompt": "...", ' .
                '"hint": "...", "feedback_correct": "...", "feedback_wrong": "...", ...type fields...}]}',
            '',
            'TRANSCRIPT:',
            self::timed_transcript((string)$instance->transcript, (int)$instance->videoduration),
        ];
        return implode("\n", $lines);
    }

    /**
     * The exact LMS Labs request body for a new generation, stored before the first call.
     *
     * @param \stdClass $instance
     * @param string $idempotencykey a new UUID
     * @return string JSON body
     * @throws lmslabs_exception when LMS Labs is not set up
     */
    public static function request_body(\stdClass $instance, string $idempotencykey): string {
        global $CFG;
        $types = array_values(array_intersect(interaction::TYPES, explode(',', (string)$instance->types)))
            ?: interaction::TYPES;
        $metadata = [
            'instanceId' => (int)$instance->id,
            'interactions' => (int)$instance->numinteractions,
            'types' => $types,
            'transcriptWords' => count(preg_split('/\s+/u', trim((string)$instance->transcript), -1, PREG_SPLIT_NO_EMPTY)),
            'videoId' => (string)$instance->videoid,
            'pluginRelease' => (string)(\core_plugin_manager::instance()->get_plugin_info('mod_aiinteractivevideo')->release ?? ''),
            'moodleRelease' => (string)$CFG->release,
        ];
        return lmslabs::build_request(self::prompt($instance), $metadata, $idempotencykey);
    }

    /**
     * Sends a stored request to LMS Labs AI (LMS Labs charges the credits for a successful generation) and turns
     * the answer into sections.
     *
     * @param \stdClass $instance
     * @param string $body the stored request body
     * @return array list of section records
     * @throws lmslabs_exception when LMS Labs cannot generate or its answer cannot be used
     */
    public static function generate(\stdClass $instance, string $body): array {
        $result = lmslabs::send($body);
        $sections = self::import($result['output'], $instance);
        if (!$sections) {
            throw new lmslabs_exception(lmslabs_exception::BADRESPONSE);
        }
        return $sections;
    }

    /**
     * Converts an AI JSON answer into section records. Invalid activities are rebuilt from the transcript
     * for the same stretch of the video, so a partly usable answer still gives a complete activity.
     *
     * @param string $json
     * @param \stdClass $instance
     * @return array list of section records (empty when nothing usable)
     */
    public static function import(string $json, \stdClass $instance): array {
        $json = trim($json);
        // Remove a Markdown code fence around the answer.
        $json = preg_replace('/^\x60{3}(?:json)?\s*|\s*\x60{3}$/i', '', $json);
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        if ($start === false || $end === false) {
            return [];
        }
        $decoded = json_decode(substr($json, $start, $end - $start + 1), true);
        $list = $decoded['sections'] ?? (is_array($decoded) && array_is_list($decoded) ? $decoded : null);
        if (!is_array($list) || !$list) {
            return [];
        }
        $cues = transcript::parse((string)$instance->transcript, (int)$instance->videoduration);
        $videoend = $instance->videoduration > 0 ? (float)$instance->videoduration : transcript::estimated_end($cues);
        $allowed = array_values(array_intersect(interaction::TYPES, explode(',', (string)$instance->types)))
            ?: interaction::TYPES;

        // Times first, so sections are ordered and contiguous.
        $rows = [];
        foreach (array_slice($list, 0, 20) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $startsec = transcript::parse_clock((string)($item['start'] ?? ''));
            $endsec = transcript::parse_clock((string)($item['end'] ?? ''));
            if ($startsec === null) {
                continue;
            }
            $rows[] = ['item' => $item, 'start' => max(0, $startsec), 'end' => $endsec];
        }
        usort($rows, fn($a, $b) => $a['start'] <=> $b['start']);
        if (!$rows) {
            return [];
        }
        $rows[0]['start'] = 0;
        foreach ($rows as $i => $row) {
            $next = $rows[$i + 1]['start'] ?? null;
            $rows[$i]['end'] = $next ?? max($row['start'] + 5, min($videoend, $row['end'] ?? $videoend));
            if ($next === null && $videoend > $row['start']) {
                $rows[$i]['end'] = $videoend;
            }
        }
        $rows = array_values(array_filter($rows, fn($r) => $r['end'] - $r['start'] >= 3));

        // Transcript text of each stretch, used to rebuild any activity the AI got wrong.
        $parts = [];
        foreach ($rows as $i => $row) {
            $slice = array_values(array_filter($cues, fn($c) => $c['start'] >= $row['start'] && $c['start'] < $row['end']));
            $parts[$i] = ['start' => $row['start'], 'end' => $row['end'],
                'text' => implode(' ', array_column($slice, 'text')), 'sentences' => segmenter::units($slice)];
        }
        $fallback = null;

        $sections = [];
        foreach ($rows as $i => $row) {
            $item = $row['item'];
            $type = (string)($item['type'] ?? '');
            $content = in_array($type, $allowed, true) ? interaction::normalise($type, $item) : null;
            $title = interaction::clean($item['title'] ?? '', 120);
            if (!$content) {
                if ($fallback === null) {
                    $usable = array_filter($parts, fn($p) => $p['text'] !== '');
                    $fallback = $usable ? builtin_generator::build($usable, $allowed) : [];
                }
                if (empty($fallback[$i])) {
                    continue;
                }
                $built = clone $fallback[$i];
                $built->title = $title !== '' ? $title : $built->title;
                $sections[] = $built;
                continue;
            }
            $sections[] = (object)[
                'title' => $title !== '' ? $title : get_string('gen_parttitle', 'mod_aiinteractivevideo', $i + 1),
                'starttime' => round($row['start'], 2),
                'endtime' => round($row['end'], 2),
                'type' => $type,
                'content' => json_encode($content),
                'hint' => interaction::clean($item['hint'] ?? '', 300),
                'feedbackcorrect' => interaction::clean($item['feedback_correct'] ?? ($item['feedbackcorrect'] ?? ''), 400),
                'feedbackwrong' => interaction::clean($item['feedback_wrong'] ?? ($item['feedbackwrong'] ?? ''), 400),
            ];
        }
        return $sections;
    }
}
