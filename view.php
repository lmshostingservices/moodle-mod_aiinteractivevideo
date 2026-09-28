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

/**
 * Activity page: the interactive video player.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_aiinteractivevideo\local\manager;
use mod_aiinteractivevideo\local\transcript;
use mod_aiinteractivevideo\local\youtube;

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'aiinteractivevideo');
$instance = $DB->get_record('aiinteractivevideo', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aiinteractivevideo:view', $context);

aiinteractivevideo_view($instance, $course, $cm, $context);

$PAGE->set_url('/mod/aiinteractivevideo/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('aiv-page');

$canmanage = has_capability('mod/aiinteractivevideo:manage', $context);
$canattempt = !isguestuser() && ($canmanage || has_capability('mod/aiinteractivevideo:attempt', $context));
$sections = manager::get_sections($instance->id);
// A queued AI build hides the activity from learners for up to 15 minutes; after that the built-in version is used.
$pending = (int)$instance->genstatus === manager::GEN_PENDING && time() - (int)$instance->timemodified < 900;
$ready = $sections && $instance->videoid !== '' && (!$pending || $canmanage);
$summary = manager::user_summary($instance, (int)$USER->id);

// Pass mark (grade to pass) in points.
$passpoints = 0;
if ($instance->grade > 0) {
    require_once($CFG->libdir . '/gradelib.php');
    $gradeitem = grade_item::fetch(
        ['itemtype' => 'mod', 'itemmodule' => 'aiinteractivevideo',
        'iteminstance' => $instance->id, 'courseid' => $course->id, 'itemnumber' => 0]
    );
    if ($gradeitem && $gradeitem->gradepass > 0) {
        $passpoints = (float)$gradeitem->gradepass;
    }
}

$str = fn($k, $a = null) => get_string($k, 'mod_aiinteractivevideo', $a);
$compare = function (array $values) use ($str): array {
    $rows = [];
    foreach ($values as $key => [$yes, $text]) {
        $rows[] = ['label' => $str('cmp_' . $key), 'text' => $text, 'yes' => $yes];
    }
    return $rows;
};
$attemptstext = $instance->maxattempts ? $str('cmp_attempts_n', $instance->maxattempts) : $str('cmp_attempts_unlimited');
$gradedmode = manager::graded_mode($instance);

$modes = [];
if ($instance->allowlearn) {
    $done = $summary['learn'];
    $modes[] = [
        'key' => 'learn',
        'title' => $str('learnmode'),
        'bestfor' => $str('bestfor_learn'),
        'enabled' => $ready && $canattempt,
        'rows' => $compare(
            [
                'hints' => [true, $str('cmp_hints_yes')],
                'feedback' => [true, $str('cmp_feedback_instant')],
                'replay' => [true, $str('cmp_replay_text')],
                'graded' => [$gradedmode === 'learn', $gradedmode === 'learn' ? $str('cmp_graded_yes', $attemptstext) :
                    $str('cmp_graded_no')],
            ]
        ),
        'done' => $done !== null,
        'meta' => $done !== null ? $str('bestscore', $done['percent']) : '',
        'resume' => !empty($summary['inprogress']['learn']),
    ];
}
if ($instance->allowtest) {
    $done = $summary['test'];
    $meta = [];
    if ($instance->maxattempts) {
        $meta[] = $str(
            'attemptsused',
            ['used' => $instance->maxattempts - max(0, $summary['attemptsleft']),
            'max' => $instance->maxattempts]
        );
    }
    if ($done !== null) {
        $meta[] = $str('bestscore', $done['percent']);
    }
    $modes[] = [
        'key' => 'test',
        'title' => $str('testmode'),
        'bestfor' => $str('bestfor_test'),
        'enabled' => $ready && $canattempt && ($summary['attemptsleft'] !== 0 || !empty($summary['inprogress']['test'])),
        'rows' => $compare(
            [
                'hints' => [false, $str('cmp_hints_no')],
                'feedback' => [true, $str('cmp_feedback_short')],
                'replay' => [true, $str('cmp_replay_text')],
                'graded' => [true, $str('cmp_graded_yes', $attemptstext)],
            ]
        ),
        'done' => $done !== null,
        'meta' => implode(' · ', $meta),
        'resume' => !empty($summary['inprogress']['test']),
        'nomore' => $summary['attemptsleft'] === 0 && empty($summary['inprogress']['test']),
    ];
}

// Chapters shown on the start screen.
$chapters = [];
foreach (array_values($sections) as $i => $section) {
    $chapters[] = [
        'number' => $i + 1,
        'title' => format_string($section->title, true, ['context' => $context, 'escape' => false]),
        'time' => transcript::clock((float)$section->starttime),
        'type' => get_string($section->type, 'mod_aiinteractivevideo'),
        'typekey' => $section->type,
    ];
}
$duration = (int)$instance->videoduration ?: (int)(end($sections) ? end($sections)->endtime : 0);

$config = [
    'cmid' => (int)$cm->id,
    'videoid' => $instance->videoid,
    'name' => format_string($instance->name, true, ['context' => $context, 'escape' => false]),
    'canattempt' => $canattempt,
    'canmanage' => $canmanage,
    'sounds' => (int)$instance->sounds,
    'preventskip' => (int)$instance->preventskip,
    'leaderboard' => (int)$instance->leaderboard,
    'maxtries' => (int)$instance->maxtries,
    'maxattempts' => (int)$instance->maxattempts,
    'attemptsleft' => (int)$summary['attemptsleft'],
    'gradedmode' => $gradedmode,
    'passpoints' => $passpoints,
    'count' => count($sections),
    'duration' => $duration,
    'durationtext' => transcript::clock($duration),
    'resume' => $summary['inprogress'],
    'pending' => $pending,
];

$templatedata = [
    'uniqid' => 'aiv-' . $cm->id,
    'config' => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    'accent' => $instance->accent,
    'name' => format_string($instance->name, true, ['context' => $context, 'escape' => false]),
    'thumbnail' => $instance->videoid !== '' ? youtube::thumbnail($instance->videoid) : '',
    'count' => count($sections),
    'durationtext' => $duration ? transcript::clock($duration) : '',
    'modes' => $modes,
    'chapters' => $chapters,
    'ready' => $ready,
    'pending' => $pending && $canmanage,
    'failed' => (int)$instance->genstatus === manager::GEN_FAILED && $canmanage,
    'failmessage' => $canmanage ? (
        manager::generation_error($instance->id)['message'] ??
        get_string('aistatusfailed', 'mod_aiinteractivevideo')
    ) : '',
    'buyurl' => $canmanage ? (manager::generation_error($instance->id)['buyurl'] ?? '') : '',
    'canmanage' => $canmanage,
    'editurl' => (new moodle_url('/mod/aiinteractivevideo/edit.php', ['id' => $cm->id]))->out(false),
    'reporturl' => has_capability('mod/aiinteractivevideo:viewreports', $context)
        ? (new moodle_url('/mod/aiinteractivevideo/report.php', ['id' => $cm->id]))->out(false) : null,
    'guest' => !$canattempt,
    'passpoints' => $passpoints ? format_float($passpoints, -1) : '',
];

$PAGE->requires->js_call_amd('mod_aiinteractivevideo/player', 'init', ['#aiv-' . $cm->id]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_aiinteractivevideo/view', $templatedata);
echo $OUTPUT->footer();
