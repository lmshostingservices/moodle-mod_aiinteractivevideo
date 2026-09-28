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
 * Lightweight language analysis used by the built-in generator: sentences, keywords and key phrases.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_analyser {
    /** @var string[] English stop words (function words and transcript filler). */
    private const STOPWORDS = [
        'a', 'about', 'above', 'actually', 'after', 'again', 'against', 'all', 'almost', 'also', 'always', 'am',
        'among', 'an', 'and', 'another', 'any', 'anyone', 'anything', 'are', 'around', 'as', 'at', 'away', 'back',
        'basically', 'be', 'because', 'been', 'before', 'being', 'below', 'between', 'both', 'but', 'by', 'came',
        'can', 'cannot', 'could', 'did', 'do', 'does', 'doing', 'done', 'down', 'during', 'each', 'either', 'else',
        'enough', 'even', 'ever', 'every', 'everyone', 'everything', 'few', 'first', 'for', 'from', 'further',
        'get', 'gets', 'getting', 'give', 'given', 'go', 'goes', 'going', 'gone', 'gonna', 'good', 'got', 'gotta',
        'had', 'has', 'have', 'having', 'he', 'her', 'here', 'hers', 'herself', 'him', 'himself', 'his', 'how',
        'however', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'itself', 'just', 'keep', 'kind', 'know', 'last',
        'let', 'lets', 'like', 'little', 'look', 'lot', 'lots', 'made', 'make', 'makes', 'making', 'many', 'may',
        'maybe', 'me', 'mean', 'might', 'more', 'most', 'much', 'must', 'my', 'myself', 'need', 'never', 'new',
        'next', 'no', 'nor', 'not', 'nothing', 'now', 'of', 'off', 'often', 'oh', 'ok', 'okay', 'on', 'once', 'one',
        'only', 'or', 'other', 'others', 'our', 'ours', 'ourselves', 'out', 'over', 'own', 'part', 'people',
        'pretty', 'put', 'quite', 'rather', 'really', 'right', 'said', 'same', 'say', 'saying', 'says', 'see',
        'seen', 'she', 'should', 'show', 'since', 'so', 'some', 'something', 'sometimes', 'sort', 'still', 'such',
        'sure', 'take', 'talk', 'talking', 'tell', 'than', 'thank', 'thanks', 'that', 'thats', 'the', 'their',
        'theirs', 'them', 'themselves', 'then', 'there', 'these', 'they', 'thing', 'things', 'think', 'this',
        'those', 'though', 'through', 'time', 'to', 'today', 'together', 'too', 'took', 'toward', 'towards',
        'try', 'two', 'uh', 'um', 'under', 'until', 'up', 'upon', 'us', 'use', 'used', 'using', 'very', 'video',
        'videos', 'want', 'wanna', 'was', 'way', 'we', 'well', 'went', 'were', 'what', 'whatever', 'when', 'where',
        'whether', 'which', 'while', 'who', 'whole', 'whom', 'why', 'will', 'with', 'within', 'without', 'would',
        'yeah', 'yes', 'yet', 'you', 'your', 'yours', 'yourself', 'yourselves', 'going', 'called', 'three', 'four',
        'five', 'ten', 'hundred', 'thousand', 'second', 'seconds', 'minute', 'minutes', 'welcome', 'hey', 'hello',
        'guys', 'channel', 'subscribe', 'gonna', 'stuff', 'already', 'anyway', 'able', 'dont', 'didnt', 'doesnt',
        'isnt', 'wasnt', 'arent', 'youre', 'theyre', 'weve', 'youve', 'ive', 'id', 'ill', 'its', 'whats', 'theres',
        'heres', 'lets', 'cant', 'wont', 'im', 'hes', 'shes', 'certain', 'different', 'example', 'important',
        'means', 'number', 'point', 'start', 'started', 'end', 'come', 'comes', 'coming', 'find', 'found', 'found',
        'known', 'less', 'long', 'mostly', 'needs', 'order', 'place', 'several', 'side', 'simply', 'small', 'big',
        'large', 'high', 'low', 'lower', 'higher', 'great', 'best', 'better', 'bit', 'called', 'case', 'looks',
        'looking', 'work', 'works', 'working', 'year', 'years', 'day', 'days', 'able', 'onto', 'unless',
        'fact', 'facts', 'impossible', 'possible', 'difficult', 'easy', 'simple', 'simply', 'clear', 'obvious',
        'gives', 'given', 'giving', 'makes', 'whole', 'fresh', 'real', 'reality', 'true', 'idea', 'ideas',
        'actual', 'complete', 'completely', 'entire', 'several', 'various', 'enough', 'each', 'least', 'next',
        'solve', 'problem', 'problems', 'thing', 'understand', 'understanding', 'learn', 'learning', 'imagine',
        'notice', 'watch', 'feel', 'feeling', 'hold', 'place', 'meaning', 'means', 'mean', 'wasnt', 'werent',
        'doesnt', 'shouldnt', 'wouldnt', 'couldnt', 'youll', 'well', 'theyll', 'hand', 'hands', 'kinds', 'type',
        'types', 'form', 'forms', 'part', 'parts', 'step', 'steps', 'first', 'last', 'second', 'third', 'final',
        'lets', 'called', 'call', 'calls', 'less', 'more', 'most', 'least', 'without', 'within', 'inside',
        'outside', 'remaining', 'become', 'becomes', 'became', 'apparent', 'potentially', 'superficially',
        'extraordinarily', 'extraordinarly', 'spectacularly', 'rhythmically', 'seamlessly', 'quickly', 'slowly',
        'always', 'usually', 'instead', 'therefore', 'although', 'though', 'amongst', 'among', 'everyone',
        'someone', 'anyone', 'nobody', 'everything', 'certainly', 'probably', 'basically', 'exactly',
        'continue', 'continues', 'continued', 'tries', 'tried', 'sends', 'send', 'sent', 'rise', 'rises', 'shows',
        'looks', 'seems', 'seem', 'explains', 'explain', 'marks', 'makes', 'thinking', 'signals', 'point', 'points',
        'dependable', 'important', 'difference', 'resonance', 'getting', 'started', 'studying', 'searches',
    ];

    /**
     * Normalised word tokens.
     *
     * @param string $text
     * @return string[]
     */
    public static function words(string $text): array {
        $text = \core_text::strtolower($text);
        $text = str_replace(['’', '‘'], "'", $text);
        preg_match_all("/[\p{L}\p{N}][\p{L}\p{N}'-]*/u", $text, $m);
        return array_map(fn($w) => trim(str_replace("'", '', $w), '-'), $m[0]);
    }

    /**
     * Whether a (normalised) word carries meaning.
     *
     * @param string $word
     * @return bool
     */
    public static function is_content_word(string $word): bool {
        static $stop = null;
        if ($stop === null) {
            $stop = array_flip(self::STOPWORDS);
        }
        return \core_text::strlen($word) >= 4 && !isset($stop[$word]) && !preg_match('/^\d+$/', $word);
    }

    /**
     * Content words of a text.
     *
     * @param string $text
     * @return string[]
     */
    public static function content_words(string $text): array {
        return array_values(array_filter(self::words($text), [self::class, 'is_content_word']));
    }

    /**
     * Light stemming so "valves" and "valve" count as one term.
     *
     * @param string $word
     * @return string
     */
    public static function stem(string $word): string {
        foreach (['ies' => 'y', 'sses' => 'ss', 'ing' => '', 'ed' => '', 's' => ''] as $suffix => $replace) {
            $len = strlen($suffix);
            if (strlen($word) > $len + 3 && substr($word, -$len) === $suffix && substr($word, -2) !== 'ss') {
                return substr($word, 0, -$len) . $replace;
            }
        }
        return $word;
    }

    /**
     * Splits text into sentences. Unpunctuated text (auto captions) is split into chunks of about 18 words.
     *
     * @param string $text
     * @return string[]
     */
    public static function sentences(string $text): array {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/(?<=[.!?…])\s+(?=["“\'(]?[\p{Lu}\p{N}])/u', $text);
        $out = [];
        foreach ($parts as $part) {
            $words = preg_split('/\s+/u', trim($part));
            if (count($words) <= 40) {
                $out[] = trim($part);
                continue;
            }
            foreach (array_chunk($words, 18) as $chunk) {
                $out[] = implode(' ', $chunk);
            }
        }
        return array_values(array_filter($out, fn($s) => $s !== ''));
    }

    /**
     * Shortens a sentence to at most $max words at a natural break, adding an ellipsis when cut.
     *
     * @param string $text
     * @param int $max
     * @return string
     */
    public static function shorten(string $text, int $max = 16): string {
        $text = trim($text);
        $words = preg_split('/\s+/u', $text);
        if (count($words) <= $max) {
            return self::tidy($text);
        }
        $cut = implode(' ', array_slice($words, 0, $max));
        $cut = rtrim($cut, ",;:-–— ");
        return self::tidy($cut) . '…';
    }

    /**
     * Capitalises the first letter and removes leading filler.
     *
     * @param string $text
     * @return string
     */
    public static function tidy(string $text): string {
        $text = trim(preg_replace('/^(and|so|but|um|uh|okay|ok|well|now|also)\b[\s,]*/iu', '', trim($text)));
        if ($text === '') {
            return '';
        }
        return \core_text::strtoupper(\core_text::substr($text, 0, 1)) . \core_text::substr($text, 1);
    }

    /**
     * Title case for short phrases.
     *
     * @param string $text
     * @return string
     */
    public static function title(string $text): string {
        $small = ['and', 'or', 'of', 'the', 'a', 'an', 'in', 'on', 'to', 'for', 'vs', 'da', 'de', 'del', 'du', 'van', 'von'];
        $words = explode(' ', trim($text));
        foreach ($words as $i => $w) {
            if ($i > 0 && in_array($w, $small, true)) {
                continue;
            }
            $words[$i] = \core_text::strtoupper(\core_text::substr($w, 0, 1)) . \core_text::substr($w, 1);
        }
        return implode(' ', $words);
    }

    /**
     * Ranks the distinctive terms of each document against the whole set.
     *
     * Scores combine TF-IDF (distinctive for the part), how often the term recurs across the whole transcript
     * (domain vocabulary), proper nouns and hyphenated compounds, and repeated two-word terms such as
     * "pulmonary artery". Adverbs and generic words are ignored. Returns surface words or phrases, best first.
     *
     * @param string[] $documents
     * @param int $limit
     * @return array[] keyed like $documents
     */
    public static function keywords(array $documents, int $limit = 12): array {
        $all = implode(' ', $documents);
        // Words written with a capital letter in the middle of a sentence are likely names or proper terms.
        preg_match_all('/(?<![.!?]\s)(?<=\s)(\p{Lu}[\p{Ll}]{3,})/u', $all, $caps);
        $proper = array_flip(array_map([\core_text::class, 'strtolower'], $caps[1]));
        // Repeated two-word terms across the transcript.
        $phrases = [];
        $words = self::words($all);
        for ($i = 0; $i < count($words) - 1; $i++) {
            if (self::is_content_word($words[$i]) && self::is_content_word($words[$i + 1]) &&
                    !self::is_adverb($words[$i]) && !self::is_adverb($words[$i + 1])) {
                $pair = $words[$i] . ' ' . $words[$i + 1];
                $phrases[$pair] = ($phrases[$pair] ?? 0) + 1;
            }
        }
        $phrases = array_filter($phrases, fn($c) => $c >= 2);

        $global = [];
        $df = [];
        $tf = [];
        $surface = [];
        foreach ($documents as $key => $doc) {
            $tf[$key] = [];
            foreach (self::content_words($doc) as $w) {
                if (self::is_adverb($w)) {
                    continue;
                }
                $s = self::stem($w);
                $tf[$key][$s] = ($tf[$key][$s] ?? 0) + 1;
                $global[$s] = ($global[$s] ?? 0) + 1;
                $surface[$s][$w] = ($surface[$s][$w] ?? 0) + 1;
            }
            $lower = \core_text::strtolower($doc);
            foreach (array_keys($phrases) as $phrase) {
                $count = substr_count($lower, $phrase);
                if ($count) {
                    $tf[$key]['#' . $phrase] = $count;
                    $global['#' . $phrase] = ($global['#' . $phrase] ?? 0) + $count;
                    $surface['#' . $phrase][$phrase] = $count;
                }
            }
            foreach (array_keys($tf[$key]) as $s) {
                $df[$s] = ($df[$s] ?? 0) + 1;
            }
        }
        $n = max(1, count($documents));
        $out = [];
        foreach ($tf as $key => $terms) {
            $scores = [];
            foreach ($terms as $s => $count) {
                $idf = log(($n + 1) / ($df[$s])) + 0.3;
                $domain = 1 + log(1 + $global[$s]) / 2;
                $weight = min(1.4, 0.7 + strlen($s) / 14);
                $word = (string)array_key_first($surface[$s]);
                if (isset($proper[$word])) {
                    $weight *= 1.4;
                }
                if (strpos($word, '-') !== false) {
                    $weight *= 1.3;
                }
                if ($s[0] === '#') {
                    $weight *= 1.6;
                }
                $scores[$s] = $count * $idf * $domain * $weight;
            }
            arsort($scores);
            $chosen = [];
            foreach (array_keys($scores) as $s) {
                arsort($surface[$s]);
                $word = (string)array_key_first($surface[$s]);
                // Skip a word already covered by a chosen phrase (and vice versa).
                foreach ($chosen as $c) {
                    $inside = strpos(' ' . $c . ' ', ' ' . $word . ' ') !== false;
                    if ($inside || strpos(' ' . $word . ' ', ' ' . $c . ' ') !== false) {
                        continue 2;
                    }
                }
                $chosen[] = $word;
                if (count($chosen) >= $limit) {
                    break;
                }
            }
            $out[$key] = $chosen;
        }
        return $out;
    }

    /**
     * Lower-cased words that are written with a capital letter in the middle of a sentence (names).
     *
     * @param string $text
     * @return array word => true
     */
    public static function proper_nouns(string $text): array {
        preg_match_all('/(?<![.!?]\s)(?<=\s)(\p{Lu}[\p{Ll}]{2,})/u', $text, $caps);
        return array_flip(array_map([\core_text::class, 'strtolower'], $caps[1]));
    }

    /**
     * Whether a word looks like an adverb (ends in "ly"), which rarely makes a good key term.
     *
     * @param string $word
     * @return bool
     */
    public static function is_adverb(string $word): bool {
        return strlen($word) > 4 && substr($word, -2) === 'ly' && !in_array(
            $word,
            ['family', 'assembly', 'supply',
            'italy', 'anomaly', 'butterfly', 'monopoly', 'reply', 'rally', 'belly', 'jelly', 'bully'],
            true
        );
    }

    /**
     * The most repeated two-word phrase made of content words, if any repeats.
     *
     * @param string $text
     * @return string|null
     */
    public static function key_phrase(string $text): ?string {
        $words = self::words($text);
        $counts = [];
        for ($i = 0; $i < count($words) - 1; $i++) {
            if (self::is_content_word($words[$i]) && self::is_content_word($words[$i + 1])) {
                $pair = $words[$i] . ' ' . $words[$i + 1];
                $counts[$pair] = ($counts[$pair] ?? 0) + 1;
            }
        }
        arsort($counts);
        $best = array_key_first($counts);
        return ($best !== null && $counts[$best] >= 2) ? $best : null;
    }

    /**
     * Whether a text contains a word (case-insensitive, whole word, allowing a plural "s").
     *
     * @param string $text
     * @param string $word
     * @return bool
     */
    public static function contains_word(string $text, string $word): bool {
        return (bool)preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . 's?(?![\p{L}\p{N}])/iu', $text);
    }

    /**
     * Replaces the first whole-word occurrence of $word in $text.
     *
     * @param string $text
     * @param string $word
     * @param string $replacement use {w} for the matched word
     * @return string|null null when the word is not found
     */
    public static function replace_word(string $text, string $word, string $replacement): ?string {
        $pattern = '/(?<![\p{L}\p{N}])(' . preg_quote($word, '/') . ')(?![\p{L}\p{N}])/iu';
        if (!preg_match($pattern, $text)) {
            return null;
        }
        return preg_replace_callback($pattern, fn($m) => str_replace('{w}', $m[1], $replacement), $text, 1);
    }
}
