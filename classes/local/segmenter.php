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
 * Divides a timed transcript into topic sections.
 *
 * Uses lexical cohesion (a TextTiling-style method): the transcript is cut where the vocabulary changes most,
 * searching near evenly spaced target points so the sections stay balanced in length.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class segmenter {
    /**
     * Builds timed sentence units from cues.
     *
     * @param array $cues from transcript::parse()
     * @return array list of ['start' => float, 'text' => string]
     */
    public static function units(array $cues): array {
        $units = [];
        $buffer = '';
        $bufferstart = null;
        foreach ($cues as $cue) {
            $sentences = text_analyser::sentences($cue['text']);
            foreach ($sentences as $idx => $sentence) {
                if ($bufferstart === null) {
                    $bufferstart = $cue['start'];
                }
                $buffer = trim($buffer . ' ' . $sentence);
                $ends = (bool)preg_match('/[.!?…]["”\')]*$/u', $sentence);
                $long = str_word_count($buffer) >= 22;
                if ($ends || $long || ($idx < count($sentences) - 1)) {
                    $units[] = ['start' => (float)$bufferstart, 'text' => $buffer];
                    $buffer = '';
                    $bufferstart = null;
                }
            }
        }
        if ($buffer !== '') {
            $units[] = ['start' => (float)$bufferstart, 'text' => $buffer];
        }
        return $units;
    }

    /**
     * Splits the transcript into sections.
     *
     * @param array $cues
     * @param int $count wanted number of sections
     * @param float $end end of the video in seconds (0 = estimate from the transcript)
     * @return array list of ['start' => float, 'end' => float, 'text' => string, 'sentences' => array]
     */
    public static function split(array $cues, int $count, float $end = 0): array {
        $units = self::units($cues);
        if (!$units) {
            return [];
        }
        $end = $end > 0 ? $end : transcript::estimated_end($cues);
        $count = max(1, min($count, intdiv(count($units), 2) ?: 1));
        $boundaries = $count > 1 ? self::boundaries($units, $count, $end) : [];
        $sections = [];
        $startindex = 0;
        $cuts = array_merge($boundaries, [count($units)]);
        foreach ($cuts as $i => $cut) {
            $slice = array_slice($units, $startindex, $cut - $startindex);
            if (!$slice) {
                continue;
            }
            $sections[] = [
                'start' => $i === 0 ? 0.0 : (float)$slice[0]['start'],
                'end' => 0.0,
                'text' => implode(' ', array_column($slice, 'text')),
                'sentences' => $slice,
            ];
            $startindex = $cut;
        }
        foreach ($sections as $i => $section) {
            $sections[$i]['end'] = isset($sections[$i + 1]) ? $sections[$i + 1]['start'] : $end;
        }
        return $sections;
    }

    /**
     * Chooses the unit indexes where new sections start.
     *
     * @param array $units
     * @param int $count
     * @param float $end
     * @return int[]
     */
    private static function boundaries(array $units, int $count, float $end): array {
        $n = count($units);
        $vectors = array_map(function ($u) {
            $v = [];
            foreach (text_analyser::content_words($u['text']) as $w) {
                $s = text_analyser::stem($w);
                $v[$s] = ($v[$s] ?? 0) + 1;
            }
            return $v;
        }, $units);

        // Similarity across each gap (between unit i-1 and i), using a window of units either side.
        $window = max(2, min(5, intdiv($n, $count * 3) ?: 2));
        $gap = array_fill(0, $n, 1.0);
        for ($i = 1; $i < $n; $i++) {
            $left = self::merge(array_slice($vectors, max(0, $i - $window), min($window, $i)));
            $right = self::merge(array_slice($vectors, $i, $window));
            $gap[$i] = self::cosine($left, $right);
        }
        // Depth score: how much lower the gap is than the peaks around it.
        $depth = array_fill(0, $n, 0.0);
        for ($i = 1; $i < $n; $i++) {
            $lpeak = $gap[$i];
            for ($j = $i - 1; $j >= 1 && $gap[$j] >= $lpeak; $j--) {
                $lpeak = $gap[$j];
            }
            $rpeak = $gap[$i];
            for ($j = $i + 1; $j < $n && $gap[$j] >= $rpeak; $j++) {
                $rpeak = $gap[$j];
            }
            $depth[$i] = ($lpeak - $gap[$i]) + ($rpeak - $gap[$i]);
            // Sentence starts that announce a new topic get a small boost.
            if (preg_match('/^(so,? )?(next|now|let\'?s|moving on|another|the (next|second|third|final|last)|' .
                    'first(ly)?|second(ly)?|third(ly)?|finally|in summary|to summari[sz]e)\b/i', $units[$i]['text'])) {
                $depth[$i] += 0.15;
            }
        }
        $cuts = [];
        $previous = 0;
        for ($k = 1; $k < $count; $k++) {
            $target = $end * $k / $count;
            $span = $end / $count * 0.4;
            $best = null;
            $bestscore = -INF;
            for ($i = $previous + 2; $i <= $n - 2 * ($count - $k); $i++) {
                $t = $units[$i]['start'];
                if ($t < $target - $span) {
                    continue;
                }
                if ($t > $target + $span) {
                    break;
                }
                // Prefer deep valleys, lightly penalising distance from the target.
                $score = $depth[$i] - abs($t - $target) / max(1, $span) * 0.12;
                if ($score > $bestscore) {
                    $bestscore = $score;
                    $best = $i;
                }
            }
            if ($best === null) {
                // No unit near the target: take the first unit at or after it.
                for ($i = $previous + 1; $i < $n; $i++) {
                    if ($units[$i]['start'] >= $target) {
                        $best = $i;
                        break;
                    }
                }
            }
            if ($best === null || $best <= $previous || $best >= $n) {
                break;
            }
            $cuts[] = $best;
            $previous = $best;
        }
        return $cuts;
    }

    /**
     * Sums term vectors.
     *
     * @param array $vectors
     * @return array
     */
    private static function merge(array $vectors): array {
        $out = [];
        foreach ($vectors as $v) {
            foreach ($v as $k => $c) {
                $out[$k] = ($out[$k] ?? 0) + $c;
            }
        }
        return $out;
    }

    /**
     * Cosine similarity.
     *
     * @param array $a
     * @param array $b
     * @return float
     */
    private static function cosine(array $a, array $b): float {
        if (!$a || !$b) {
            return 0.0;
        }
        $dot = 0;
        foreach ($a as $k => $v) {
            if (isset($b[$k])) {
                $dot += $v * $b[$k];
            }
        }
        $na = sqrt(array_sum(array_map(fn($x) => $x * $x, $a)));
        $nb = sqrt(array_sum(array_map(fn($x) => $x * $x, $b)));
        return ($na && $nb) ? $dot / ($na * $nb) : 0.0;
    }
}
