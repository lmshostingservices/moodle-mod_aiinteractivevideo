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
 * Built-in generator: builds sections and interactions straight from the transcript, without any AI service.
 *
 * Every interaction is made from the transcript itself: quotes from the section, its most distinctive terms
 * (TF-IDF against the other sections) and, for distractors, terms that belong to other sections of the video.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class builtin_generator {
    /** @var string[] Default rotation, chosen so consecutive sections feel different. */
    private const ROTATION = ['fillblanks', 'cardselect', 'ordering', 'matching', 'swipe', 'categorize', 'spotmistake',
        'unscramble'];

    /**
     * Generates sections.
     *
     * @param string $transcriptraw pasted transcript
     * @param int $count wanted number of interactions
     * @param string[] $types allowed interaction types
     * @param int $duration video length (0 = unknown)
     * @return array list of section records (title, starttime, endtime, type, content, hint, feedbackcorrect,
     *               feedbackwrong)
     */
    public static function generate(string $transcriptraw, int $count, array $types, int $duration = 0): array {
        $cues = transcript::parse($transcriptraw, $duration);
        $parts = segmenter::split($cues, $count, (float)$duration);
        return array_values(self::build($parts, $types));
    }

    /**
     * Builds interactions for already segmented parts.
     *
     * @param array $parts from segmenter::split()
     * @param string[] $types
     * @return array section records keyed by part index (parts that could not be used are missing)
     */
    public static function build(array $parts, array $types): array {
        if (!$parts) {
            return [];
        }
        $originalkeys = array_keys($parts);
        $parts = array_values($parts);
        $types = array_values(array_intersect(self::ROTATION, $types ?: interaction::TYPES)) ?: self::ROTATION;
        $keywords = text_analyser::keywords(array_column($parts, 'text'), 16);
        // Terms that come up in most parts (the video's overall subject) make weak questions: drop them.
        $n = count($parts);
        if ($n >= 3) {
            $threshold = max(2, (int)ceil($n * 0.6));
            foreach ($keywords as $i => $list) {
                $filtered = array_values(
                    array_filter(
                        $list,
                        function ($k) use ($parts, $threshold) {
                            $count = 0;
                            foreach ($parts as $part) {
                                $count += text_analyser::contains_word($part['text'], $k) ? 1 : 0;
                            }
                            return $count < $threshold;
                        }
                    )
                );
                $keywords[$i] = count($filtered) >= 3 ? $filtered : $list;
            }
        }
        $titles = [];
        foreach ($parts as $i => $part) {
            $titles[$i] = self::title($part['text'], $keywords[$i], $titles, $i);
        }
        $sections = [];
        $previoustype = '';
        foreach ($parts as $i => $part) {
            $ctx = self::context($parts, $keywords, $titles, $i);
            $built = null;
            $offset = $i % count($types);
            $order = array_merge(array_slice($types, $offset), array_slice($types, 0, $offset));
            // Avoid repeating the previous type when another type works.
            usort($order, fn($a, $b) => ($a === $previoustype) <=> ($b === $previoustype));
            foreach ($order as $type) {
                $content = self::make($type, $ctx);
                if ($content && ($content = interaction::normalise($type, $content))) {
                    $built = [$type, $content];
                    break;
                }
            }
            if (!$built) {
                $content = interaction::normalise('swipe', self::fallback($ctx));
                $built = $content ? ['swipe', $content] : null;
            }
            if (!$built) {
                continue;
            }
            [$type, $content] = $built;
            $previoustype = $type;
            $focus = implode(', ', array_slice($ctx['keywords'], 0, 2));
            $quote = text_analyser::shorten($ctx['best'], 26);
            $sections[$originalkeys[$i]] = (object)[
                'title' => $titles[$i],
                'starttime' => round($part['start'], 2),
                'endtime' => round($part['end'], 2),
                'type' => $type,
                'content' => json_encode($content),
                'hint' => $focus !== '' ? get_string('gen_hint', 'mod_aiinteractivevideo', $focus) : '',
                'feedbackcorrect' => $quote !== '' ? get_string('gen_feedbackcorrect', 'mod_aiinteractivevideo', $quote) : '',
                'feedbackwrong' => get_string(
                    'gen_feedbackwrong',
                    'mod_aiinteractivevideo',
                    $focus !== '' ? $focus :
                    $titles[$i]
                ),
            ];
        }
        return $sections;
    }

    /**
     * Section title from its key phrase or top keywords.
     *
     * @param string $text
     * @param array $keywords
     * @param array $taken titles already used
     * @param int $index
     * @return string
     */
    private static function title(string $text, array $keywords, array $taken, int $index): string {
        $candidates = [];
        if ($phrase = text_analyser::key_phrase($text)) {
            $candidates[] = $phrase;
        }
        if (count($keywords) >= 2) {
            // Two top terms that sit next to each other form one name ("Leonardo da Vinci"): use it as written.
            $k0 = preg_quote($keywords[0], '/');
            $k1 = preg_quote($keywords[1], '/');
            $gap = '(?:\\s+\\p{Ll}{1,3}){0,2}\\s+';
            $near = '/\\b(?:' . $k0 . $gap . $k1 . '|' . $k1 . $gap . $k0 . ')\\b/iu';
            if (preg_match($near, $text, $m)) {
                $candidates[] = trim($m[0], " \t,.;:");
            } else {
                $candidates[] = $keywords[0] . ' & ' . $keywords[1];
            }
        }
        if ($keywords) {
            $candidates[] = $keywords[0];
        }
        foreach ($candidates as $candidate) {
            $title = text_analyser::title($candidate);
            if (!in_array($title, $taken, true)) {
                return $title;
            }
        }
        return get_string('gen_parttitle', 'mod_aiinteractivevideo', $index + 1);
    }

    /**
     * Collects everything the builders need for one part.
     *
     * @param array $parts
     * @param array $keywords
     * @param array $titles
     * @param int $i
     * @return array
     */
    private static function context(array $parts, array $keywords, array $titles, int $i): array {
        $text = $parts[$i]['text'];
        $sentences = [];
        foreach ($parts[$i]['sentences'] as $unit) {
            $s = text_analyser::tidy($unit['text']);
            $n = count(preg_split('/\s+/u', $s));
            if ($n >= 5 && $n <= 40) {
                $sentences[] = $s;
            }
        }
        // Keywords that really occur in this part (as a whole word).
        $mine = array_values(array_filter($keywords[$i], fn($k) => text_analyser::contains_word($text, $k)));
        // Distractors: other parts' keywords that never occur in this part.
        $others = [];
        foreach ($keywords as $j => $list) {
            if ($j === $i) {
                continue;
            }
            foreach (array_slice($list, 0, 6) as $k) {
                if (!text_analyser::contains_word($text, $k) && !in_array($k, $others, true)) {
                    $others[] = $k;
                }
            }
        }
        // Order distractors by closeness in the video (nearby parts sound more plausible).
        $othersentences = [];
        foreach ($parts as $j => $part) {
            if ($j !== $i) {
                foreach ($part['sentences'] as $unit) {
                    $othersentences[] = text_analyser::tidy($unit['text']);
                }
            }
        }
        // The sentence richest in keywords is the "key quote".
        $best = '';
        $bestscore = -1;
        foreach ($sentences as $s) {
            $score = 0;
            foreach (array_slice($mine, 0, 6) as $k) {
                $score += text_analyser::contains_word($s, $k) ? 1 : 0;
            }
            if ($score > $bestscore) {
                $bestscore = $score;
                $best = $s;
            }
        }
        // Swaps for fact-or-fiction and spot-the-mistake: single common words (names read oddly mid-sentence).
        $proper = text_analyser::proper_nouns(implode(' ', array_column($parts, 'text')));
        $swaps = array_values(
            array_filter(
                $others,
                fn($o) => !preg_match('/[\s-]/u', $o) &&
                !isset($proper[\core_text::strtolower($o)])
            )
        );
        $neighbour = null;
        if ($i > 0) {
            $neighbour = $i - 1;
        } else if ($i + 1 < count($parts)) {
            $neighbour = $i + 1;
        }
        return [
            'index' => $i,
            'text' => $text,
            'sentences' => $sentences,
            'keywords' => $mine,
            'others' => $others,
            'swaps' => $swaps,
            'othersentences' => $othersentences,
            'best' => $best,
            'title' => $titles[$i],
            'neighbour' => $neighbour,
            'neighbourtitle' => $neighbour !== null ? $titles[$neighbour] : '',
            'neighbourtext' => $neighbour !== null ? $parts[$neighbour]['text'] : '',
            'neighbourkeywords' => $neighbour !== null ? $keywords[$neighbour] : [],
        ];
    }

    /**
     * Dispatches to a type builder.
     *
     * @param string $type
     * @param array $ctx
     * @return array|null
     */
    private static function make(string $type, array $ctx): ?array {
        $method = 'make_' . $type;
        return method_exists(self::class, $method) ? self::$method($ctx) : null;
    }

    /**
     * Prompt string for a type.
     *
     * @param string $type
     * @param mixed $a
     * @return string
     */
    private static function prompt(string $type, $a = null): string {
        return get_string('gen_prompt_' . $type, 'mod_aiinteractivevideo', $a);
    }

    /**
     * Sentences that contain a keyword, best first.
     *
     * @param array $ctx
     * @return array list of [sentence, keyword]
     */
    private static function keyed_sentences(array $ctx): array {
        $out = [];
        $used = [];
        foreach ($ctx['keywords'] as $k) {
            foreach ($ctx['sentences'] as $s) {
                if (!isset($used[$s]) && text_analyser::contains_word($s, $k)) {
                    $out[] = [$s, $k];
                    $used[$s] = true;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * A short window of words around a keyword, with the keyword replaced.
     *
     * @param string $sentence
     * @param string $keyword
     * @param string $replacement
     * @param int $radius
     * @return string|null
     */
    private static function window(string $sentence, string $keyword, string $replacement, int $radius = 9): ?string {
        $marked = text_analyser::replace_word($sentence, $keyword, "\u{2063}");
        if ($marked === null) {
            return null;
        }
        $words = preg_split('/\s+/u', $marked);
        $pos = 0;
        foreach ($words as $n => $w) {
            if (strpos($w, "\u{2063}") !== false) {
                $pos = $n;
                break;
            }
        }
        $from = max(0, $pos - $radius);
        $slice = array_slice($words, $from, $radius * 2 + 1);
        $text = implode(' ', $slice);
        $text = str_replace("\u{2063}", $replacement, $text);
        $prefix = $from > 0 ? '…' : '';
        $suffix = ($from + count($slice)) < count($words) ? '…' : '';
        return $prefix . text_analyser::tidy($text) . $suffix;
    }

    /**
     * Fill in the blanks from a key quote.
     *
     * @param array $ctx
     * @return array|null
     */
    private static function make_fillblanks(array $ctx): ?array {
        if (count($ctx['keywords']) < 2 || count($ctx['others']) < 2) {
            return null;
        }
        // Best passage: one or two consecutive sentences with the most keywords, up to 45 words.
        $best = null;
        $bestscore = 0;
        $n = count($ctx['sentences']);
        for ($i = 0; $i < $n; $i++) {
            foreach ([1, 2] as $len) {
                if ($i + $len > $n) {
                    continue;
                }
                $passage = implode(' ', array_slice($ctx['sentences'], $i, $len));
                if (count(preg_split('/\s+/u', $passage)) > 45) {
                    continue;
                }
                $score = 0;
                foreach (array_slice($ctx['keywords'], 0, 8) as $k) {
                    $score += text_analyser::contains_word($passage, $k) ? 1 : 0;
                }
                if ($score > $bestscore) {
                    $bestscore = $score;
                    $best = $passage;
                }
            }
        }
        if ($best === null || $bestscore < 1) {
            return null;
        }
        $blanks = 0;
        foreach (array_slice($ctx['keywords'], 0, 8) as $k) {
            if ($blanks >= 3) {
                break;
            }
            $replaced = text_analyser::replace_word($best, $k, '[[{w}]]');
            // Never blank inside an existing blank.
            if ($replaced !== null && !preg_match('/\[\[[^\]]*\[\[/u', $replaced)) {
                $best = $replaced;
                $blanks++;
            }
        }
        if ($blanks < 1) {
            return null;
        }
        return [
            'prompt' => self::prompt('fillblanks'),
            'text' => $best,
            'distractors' => array_slice($ctx['others'], 0, min(3, max(2, $blanks))),
        ];
    }

    /**
     * Card select: pick the terms used in this part.
     *
     * @param array $ctx
     * @return array|null
     */
    private static function make_cardselect(array $ctx): ?array {
        if (count($ctx['keywords']) < 3 || count($ctx['others']) < 3) {
            return null;
        }
        $cards = [];
        foreach (array_slice($ctx['keywords'], 0, 3) as $k) {
            $cards[] = ['text' => text_analyser::tidy($k), 'correct' => true];
        }
        foreach (array_slice($ctx['others'], 0, 3) as $k) {
            $cards[] = ['text' => text_analyser::tidy($k), 'correct' => false];
        }
        return ['prompt' => self::prompt('cardselect'), 'cards' => $cards];
    }

    /**
     * Ordering: put quotes from this part in sequence.
     *
     * @param array $ctx
     * @return array|null
     */
    private static function make_ordering(array $ctx): ?array {
        $pool = array_values(array_filter($ctx['sentences'], fn($s) => count(preg_split('/\s+/u', $s)) >= 6));
        if (count($pool) < 4) {
            return null;
        }
        $pick = 4;
        $items = [];
        for ($k = 0; $k < $pick; $k++) {
            $index = (int)floor($k * (count($pool) - 1) / ($pick - 1));
            $items[] = text_analyser::shorten($pool[$index], 14);
        }
        if (count(array_unique($items)) < $pick) {
            return null;
        }
        return ['prompt' => self::prompt('ordering'), 'items' => $items];
    }

    /**
     * Matching: key term to the statement it completes.
     *
     * @param array $ctx
     * @return array|null
     */
    private static function make_matching(array $ctx): ?array {
        $pairs = [];
        foreach (self::keyed_sentences($ctx) as [$sentence, $keyword]) {
            $snippet = self::window($sentence, $keyword, '_____', 8);
            if ($snippet === null) {
                continue;
            }
            // The snippet must not also contain another chosen term.
            foreach ($pairs as $p) {
                if (text_analyser::contains_word($snippet, $p['left'])) {
                    continue 2;
                }
            }
            $pairs[] = ['left' => text_analyser::tidy($keyword), 'right' => $snippet];
            if (count($pairs) === 4) {
                break;
            }
        }
        if (count($pairs) < 3) {
            return null;
        }
        return ['prompt' => self::prompt('matching'), 'pairs' => $pairs];
    }

    /** @var string[] Word pairs that flip the meaning of a statement. */
    private const OPPOSITES = [
        'left' => 'right', 'right' => 'left', 'first' => 'second', 'second' => 'first', 'open' => 'close',
        'opens' => 'closes', 'close' => 'open', 'closes' => 'opens', 'opening' => 'closing', 'closing' => 'opening',
        'more' => 'less', 'less' => 'more', 'most' => 'least', 'high' => 'low', 'low' => 'high', 'higher' => 'lower',
        'lower' => 'higher', 'before' => 'after', 'after' => 'before', 'top' => 'bottom', 'bottom' => 'top',
        'inside' => 'outside', 'outside' => 'inside', 'easy' => 'difficult', 'difficult' => 'easy', 'simple' => 'complicated',
        'complicated' => 'simple', 'fast' => 'slow', 'slow' => 'fast', 'always' => 'never', 'never' => 'always',
        'increase' => 'decrease', 'decrease' => 'increase', 'increases' => 'decreases', 'decreases' => 'increases',
        'large' => 'small', 'small' => 'large', 'big' => 'small', 'long' => 'short', 'short' => 'long',
        'hot' => 'cold', 'cold' => 'hot', 'up' => 'down', 'down' => 'up',
        'strong' => 'weak', 'weak' => 'strong', 'early' => 'late',
        'late' => 'early', 'north' => 'south', 'south' => 'north', 'east' => 'west', 'west' => 'east',
        'positive' => 'negative', 'negative' => 'positive', 'true' => 'false', 'false' => 'true',
        'efficient' => 'inefficient', 'possible' => 'impossible', 'impossible' => 'possible', 'clear' => 'unclear',
        'fresh' => 'frozen', 'quickly' => 'slowly', 'slowly' => 'quickly',
        'two' => 'three', 'three' => 'two', 'four' => 'three', 'five' => 'six', 'six' => 'five', 'ten' => 'twelve',
        'hundred' => 'thousand', 'thousand' => 'hundred', 'million' => 'billion', 'billion' => 'million',
    ];

    /**
     * Makes a statement false with one small, meaningful change: a number, an opposite, or a negation.
     *
     * @param string $sentence
     * @return array|null [false statement, changed word, original word]
     */
    private static function falsify(string $sentence): ?array {
        // Numbers: change the figure.
        if (preg_match('/(?<![\p{L}\p{N}.,])(\d+)(?![\p{N}])/u', $sentence, $m)) {
            $n = (int)$m[1];
            $new = $n >= 1900 && $n <= 2100 ? $n - 10 : ($n > 10 ? (int)round($n * 1.5) : $n + 2);
            $text = preg_replace('/(?<![\p{L}\p{N}.,])' . $m[1] . '(?![\p{N}])/u', (string)$new, $sentence, 1);
            return [$text, (string)$new, $m[1]];
        }
        // Opposites (first one found, whole word, keeping capitals).
        $words = preg_split('/(\s+)/u', $sentence, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($words as $k => $w) {
            $core = \core_text::strtolower(trim($w, ".,;:!?\"'()"));
            if (isset(self::OPPOSITES[$core]) && strpos(self::OPPOSITES[$core], ' ') === false) {
                $replacement = self::OPPOSITES[$core];
                if ($core !== trim($w, ".,;:!?\"'()") && \core_text::strlen($core) > 0) {
                    $replacement = \core_text::strtoupper(\core_text::substr($replacement, 0, 1)) .
                        \core_text::substr($replacement, 1);
                }
                $words[$k] = str_replace(trim($w, ".,;:!?\"'()"), $replacement, $w);
                return [implode('', $words), $replacement, trim($w, ".,;:!?\"'()")];
            }
        }
        // Negation.
        $flips = ["/\bdoesn't\b/iu" => 'does', "/\bdon't\b/iu" => 'do', "/\bisn't\b/iu" => 'is', "/\baren't\b/iu" => 'are',
            "/\bcan't\b/iu" => 'can', '/\bcannot\b/iu' => 'can', '/\bis not\b/iu' => 'is', '/\bare not\b/iu' => 'are',
            '/\bis\b/u' => 'is not', '/\bare\b/u' => 'are not', '/\bcan\b/u' => 'cannot', '/\bwill\b/u' => 'will not'];
        foreach ($flips as $pattern => $replacement) {
            if (preg_match($pattern, $sentence, $m)) {
                return [preg_replace($pattern, $replacement, $sentence, 1), $replacement, $m[0]];
            }
        }
        return null;
    }

    /**
     * Fact or fiction: real quotes and quotes with a term swapped.
     *
     * @param array $ctx
     * @return array|null
     */
    private static function make_swipe(array $ctx): ?array {
        $pool = array_values(array_filter($ctx['sentences'], fn($x) => count(preg_split('/\s+/u', $x)) >= 6));
        if (count($pool) < 4) {
            return null;
        }
        $facts = [];
        $fictions = [];
        foreach ($pool as $k => $sentence) {
            $short = text_analyser::shorten($sentence, 20);
            if (count($fictions) < 2 && ($false = self::falsify($short))) {
                $fictions[] = ['text' => text_analyser::tidy($false[0]), 'fact' => false];
                continue;
            }
            if (count($facts) < 2) {
                $facts[] = ['text' => $short, 'fact' => true];
            }
        }
        // Not enough natural changes: swap a key term for one from elsewhere in the video.
        foreach (self::keyed_sentences($ctx) as [$sentence, $keyword]) {
            if (count($fictions) >= 2 || !$ctx['swaps']) {
                break;
            }
            $changed = text_analyser::replace_word(
                text_analyser::shorten($sentence, 20),
                $keyword,
                $ctx['swaps'][count($fictions)
                % count($ctx['swaps'])]
            );
            if ($changed !== null && !in_array($changed, array_column($facts, 'text'), true)) {
                $fictions[] = ['text' => $changed, 'fact' => false];
            }
        }
        if (count($facts) < 2 || count($fictions) < 2) {
            return null;
        }
        return ['prompt' => self::prompt('swipe'), 'statements' => [$facts[0], $fictions[0], $facts[1], $fictions[1]]];
    }

    /**
     * Shortens a sentence but keeps a particular word visible.
     *
     * @param string $sentence
     * @param string $word
     * @return string
     */
    private static function window_whole(string $sentence, string $word): string {
        if (count(preg_split('/\s+/u', $sentence)) <= 20) {
            return text_analyser::tidy($sentence);
        }
        return self::window($sentence, $word, '{w}', 10) !== null
            ? str_replace('{w}', $word, self::window($sentence, $word, '{w}', 10))
            : text_analyser::shorten($sentence, 20);
    }

    /**
     * Category sort: this part's terms versus a neighbouring part's terms.
     *
     * @param array $ctx
     * @return array|null
     */
    private static function make_categorize(array $ctx): ?array {
        if ($ctx['neighbour'] === null || $ctx['neighbourtitle'] === '') {
            return null;
        }
        $mine = array_values(
            array_filter(
                $ctx['keywords'],
                fn($k) => !text_analyser::contains_word($ctx['neighbourtext'], $k)
            )
        );
        $theirs = array_values(
            array_filter(
                $ctx['neighbourkeywords'],
                fn($k) => text_analyser::contains_word($ctx['neighbourtext'], $k) && !text_analyser::contains_word($ctx['text'], $k)
            )
        );
        if (count($mine) < 3 || count($theirs) < 3) {
            return null;
        }
        $here = get_string('gen_thispart', 'mod_aiinteractivevideo', $ctx['title']);
        $key = $ctx['neighbour'] < $ctx['index'] ? 'gen_earlierpart' : 'gen_laterpart';
        $there = get_string($key, 'mod_aiinteractivevideo', $ctx['neighbourtitle']);
        return [
            'prompt' => self::prompt('categorize'),
            'categories' => [
                ['name' => $here, 'items' => array_map([text_analyser::class, 'tidy'], array_slice($mine, 0, 3))],
                ['name' => $there, 'items' => array_map([text_analyser::class, 'tidy'], array_slice($theirs, 0, 3))],
            ],
        ];
    }

    /**
     * Spot the mistake: a real quote with one term swapped.
     *
     * @param array $ctx
     * @return array|null
     */
    private static function make_spotmistake(array $ctx): ?array {
        // Prefer a meaningful change (number, opposite) to a real quote.
        foreach ($ctx['sentences'] as $sentence) {
            if (count(preg_split('/\s+/u', $sentence)) < 8) {
                continue;
            }
            $text = text_analyser::shorten($sentence, 26);
            $false = self::falsify($text);
            if ($false && !preg_match('/\s/u', $false[1]) && !preg_match('/\s/u', $false[2])) {
                $marked = preg_replace(
                    '/(?<![\p{L}\p{N}])' . preg_quote($false[2], '/') . '(?![\p{L}\p{N}])/u',
                    '{{' . $false[1] . '|' . $false[2] . '}}',
                    $text,
                    1
                );
                if ($marked !== null && strpos($marked, '{{') !== false) {
                    return ['prompt' => self::prompt('spotmistake'), 'text' => $marked];
                }
            }
        }
        if (!$ctx['swaps']) {
            return null;
        }
        $swap = $ctx['swaps'][0];
        foreach (self::keyed_sentences($ctx) as [$sentence, $keyword]) {
            if (count(preg_split('/\s+/u', $sentence)) < 8 || preg_match('/[\s-]/u', $keyword)) {
                continue;
            }
            $text = self::window_whole($sentence, $keyword);
            $marked = text_analyser::replace_word($text, $keyword, '{{' . $swap . '|{w}}}');
            if ($marked !== null) {
                return ['prompt' => self::prompt('spotmistake'), 'text' => $marked];
            }
        }
        return null;
    }

    /**
     * Unscramble a key term using a quote as the clue.
     *
     * @param array $ctx
     * @return array|null
     */
    private static function make_unscramble(array $ctx): ?array {
        foreach (self::keyed_sentences($ctx) as [$sentence, $keyword]) {
            $len = \core_text::strlen($keyword);
            if ($len < 5 || $len > 12 || preg_match('/[^\p{L}]/u', $keyword)) {
                continue;
            }
            $clue = self::window($sentence, $keyword, '_____', 9);
            if ($clue === null) {
                continue;
            }
            return ['prompt' => self::prompt('unscramble', $clue), 'answer' => $keyword];
        }
        return null;
    }

    /**
     * Last resort: was each statement said in this part?
     *
     * @param array $ctx
     * @return array
     */
    private static function fallback(array $ctx): array {
        $statements = [];
        foreach (array_slice($ctx['sentences'], 0, 2) as $s) {
            $statements[] = ['text' => text_analyser::shorten($s, 20), 'fact' => true];
        }
        foreach (array_slice($ctx['othersentences'], 0, 2) as $s) {
            $statements[] = ['text' => text_analyser::shorten($s, 20), 'fact' => false];
        }
        return ['prompt' => self::prompt('swipe_part'), 'statements' => $statements];
    }
}
