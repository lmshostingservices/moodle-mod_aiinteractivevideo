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
 * Parses pasted video transcripts into timed cues.
 *
 * Understands the YouTube "Show transcript" copy format (timestamp lines, or a timestamp at the start of a
 * line), SRT and WebVTT captions, bracketed timestamps such as [01:23] or (1:23), and plain text without any
 * timestamps (the timing is then spread evenly over the video).
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcript {
    /** @var float Typical speaking rate used when a transcript has no timestamps (words per second). */
    public const WORDS_PER_SECOND = 2.5;

    /**
     * Parses a transcript.
     *
     * @param string $raw pasted transcript
     * @param int $duration known video length in seconds (0 if unknown)
     * @return array list of cues ['start' => float, 'text' => string], sorted by start
     */
    public static function parse(string $raw, int $duration = 0): array {
        $raw = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $raw);
        $raw = preg_replace('/^\x{FEFF}/u', '', $raw);
        $lines = explode("\n", $raw);
        $cues = [];
        $current = null;
        $timed = false;
        $time = '(?:(\d{1,2}):)?(\d{1,2}):(\d{2})(?:[.,](\d{1,3}))?';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(WEBVTT|NOTE\b|STYLE\b|REGION\b|Kind:|Language:)/i', $line)) {
                continue;
            }
            // SRT/VTT cue timing line.
            if (preg_match('/^' . $time . '\s*-->\s*' . $time . '/', $line, $m)) {
                $timed = true;
                if ($current) {
                    $cues[] = $current;
                }
                $current = ['start' => self::seconds($m[1], $m[2], $m[3], $m[4] ?? ''), 'text' => ''];
                continue;
            }
            // SRT sequence number.
            if (preg_match('/^\d+$/', $line) && !preg_match('/^\d{1,2}:\d{2}/', $line)) {
                continue;
            }
            // Accessibility duration lines that YouTube inserts ("2 seconds", "1 minute, 5 seconds").
            if (preg_match('/^(\d+\s+(hours?|minutes?|seconds?),?\s*)+$/i', $line)) {
                continue;
            }
            // Timestamp alone, or at the start of the line: "1:23", "[01:23]", "(1:23) text", "01:23 - text".
            if (preg_match('/^[\[(]?' . $time . '[\])]?\s*[-–—:|]?\s*(.*)$/u', $line, $m)) {
                $timed = true;
                if ($current) {
                    $cues[] = $current;
                }
                $current = ['start' => self::seconds($m[1], $m[2], $m[3], $m[4] ?? ''), 'text' => trim($m[5])];
                continue;
            }
            $clean = self::clean_line($line);
            if ($clean === '') {
                continue;
            }
            if ($current === null) {
                $current = ['start' => -1, 'text' => $clean];
            } else {
                $current['text'] = trim($current['text'] . ' ' . $clean);
            }
        }
        if ($current) {
            $cues[] = $current;
        }
        foreach ($cues as $i => $cue) {
            $cues[$i]['text'] = self::clean_line($cue['text']);
        }
        $cues = array_values(array_filter($cues, fn($c) => $c['text'] !== ''));
        if (!$cues) {
            return [];
        }
        if (!$timed) {
            return self::spread(implode(' ', array_column($cues, 'text')), $duration);
        }
        // Text before the first timestamp belongs to the first cue.
        if ($cues[0]['start'] < 0) {
            $cues[0]['start'] = 0;
        }
        usort($cues, fn($a, $b) => $a['start'] <=> $b['start']);
        return self::dedupe($cues);
    }

    /**
     * Converts timestamp parts to seconds.
     *
     * @param string $h
     * @param string $m
     * @param string $s
     * @param string $ms
     * @return float
     */
    private static function seconds(string $h, string $m, string $s, string $ms): float {
        $value = ((int)$h) * 3600 + ((int)$m) * 60 + (int)$s;
        if ($ms !== '') {
            $value += ((int)str_pad($ms, 3, '0')) / 1000;
        }
        return (float)$value;
    }

    /**
     * Removes caption markup and sound tags from a line.
     *
     * @param string $line
     * @return string
     */
    public static function clean_line(string $line): string {
        $line = preg_replace('/<\d{1,2}:\d{2}(:\d{2})?[.,]\d{1,3}>/', '', $line);
        $line = strip_tags($line);
        $line = html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $line = preg_replace('/\[(music|applause|laughter|laughs|inaudible|silence|__)\]/i', '', $line);
        $line = preg_replace('/\s+/u', ' ', $line);
        return trim($line);
    }

    /**
     * Removes rolling duplicates that auto-generated captions produce.
     *
     * @param array $cues
     * @return array
     */
    private static function dedupe(array $cues): array {
        $out = [];
        $previous = '';
        foreach ($cues as $cue) {
            $text = $cue['text'];
            if ($previous !== '' && $text === $previous) {
                continue;
            }
            if ($previous !== '' && str_starts_with($text, $previous . ' ')) {
                $text = trim(substr($text, strlen($previous)));
            }
            $previous = $cue['text'];
            if ($text !== '') {
                $out[] = ['start' => $cue['start'], 'text' => $text];
            }
        }
        return $out;
    }

    /**
     * Spreads untimed text over the video, one cue per sentence (or per 15 words when there is no punctuation).
     *
     * @param string $text
     * @param int $duration
     * @return array
     */
    private static function spread(string $text, int $duration): array {
        $chunks = text_analyser::sentences($text);
        $words = max(1, str_word_count(implode(' ', $chunks)));
        $total = $duration > 0 ? $duration : (int)ceil($words / self::WORDS_PER_SECOND);
        $cues = [];
        $done = 0;
        foreach ($chunks as $chunk) {
            $cues[] = ['start' => round($total * $done / $words, 2), 'text' => $chunk];
            $done += max(1, str_word_count($chunk));
        }
        return $cues;
    }

    /**
     * Estimated end of the spoken content (used when the real video length is not known yet).
     *
     * @param array $cues
     * @return float
     */
    public static function estimated_end(array $cues): float {
        if (!$cues) {
            return 0;
        }
        $last = end($cues);
        return $last['start'] + max(2, str_word_count($last['text']) / self::WORDS_PER_SECOND) + 1;
    }

    /**
     * Formats seconds as m:ss or h:mm:ss.
     *
     * @param float $seconds
     * @return string
     */
    public static function clock(float $seconds): string {
        $seconds = max(0, (int)round($seconds));
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /**
     * Parses a clock value such as 1:23, 01:02:03 or 83 into seconds.
     *
     * @param string $value
     * @return float|null
     */
    public static function parse_clock(string $value): ?float {
        $value = trim($value);
        if (preg_match('/^\d+(\.\d+)?$/', $value)) {
            return (float)$value;
        }
        if (preg_match('/^(?:(\d{1,2}):)?(\d{1,2}):(\d{2})(?:[.,](\d{1,3}))?$/', $value, $m)) {
            return self::seconds($m[1], $m[2], $m[3], $m[4] ?? '');
        }
        return null;
    }
}
