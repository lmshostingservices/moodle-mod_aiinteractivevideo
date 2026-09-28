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
 * Reports: which interactions are too hard or take too long, and every attempt.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/completionlib.php');

use mod_aiinteractivevideo\local\manager;
use mod_aiinteractivevideo\local\transcript;

$id = required_param('id', PARAM_INT);
$tab = optional_param('tab', 'insights', PARAM_ALPHA);
$mode = optional_param('mode', '', PARAM_ALPHA);
$download = optional_param('download', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);
$attemptid = optional_param('attemptid', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'aiinteractivevideo');
$instance = $DB->get_record('aiinteractivevideo', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aiinteractivevideo:viewreports', $context);
$canmanage = has_capability('mod/aiinteractivevideo:manage', $context);

$modes = [];
if ($instance->allowtest) {
    $modes[] = 'test';
}
if ($instance->allowlearn) {
    $modes[] = 'learn';
}
if (!in_array($mode, ['learn', 'test'], true)) {
    $mode = manager::graded_mode($instance);
}
if (!in_array($tab, ['insights', 'attempts', 'attempt'], true)) {
    $tab = 'insights';
}

$baseurl = new moodle_url('/mod/aiinteractivevideo/report.php', ['id' => $cm->id, 'tab' => $tab, 'mode' => $mode]);
$PAGE->set_url($baseurl);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('reports', 'mod_aiinteractivevideo'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->set_attrs(['description' => '', 'hidecompletion' => true]);
if ($node = $PAGE->settingsnav->find('aiinteractivevideo_reports', navigation_node::TYPE_SETTING)) {
    $node->make_active();
}

// Group filter.
$groupmode = groups_get_activity_groupmode($cm);
$currentgroup = groups_get_activity_group($cm, true);
$userids = null;
if ($groupmode && $currentgroup) {
    $userids = array_map('intval', array_keys(groups_get_members($currentgroup, 'u.id')));
}

// Attempt management.
if ($action === 'delete' && $canmanage) {
    require_sesskey();
    $ids = optional_param_array('attemptids', [], PARAM_INT);
    if ($ids) {
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $params['instanceid'] = $instance->id;
        $attempts = $DB->get_records_select(
            'aiinteractivevideo_attempt',
            "id $insql AND aiinteractivevideoid = :instanceid",
            $params,
            '',
            'id, userid'
        );
        $users = [];
        foreach ($attempts as $a) {
            $DB->delete_records('aiinteractivevideo_response', ['attemptid' => $a->id]);
            $DB->delete_records('aiinteractivevideo_attempt', ['id' => $a->id]);
            $users[$a->userid] = true;
        }
        $completion = new completion_info($course);
        foreach (array_keys($users) as $uid) {
            aiinteractivevideo_update_grades($instance, $uid);
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, $uid);
            }
        }
        redirect(
            $baseurl,
            get_string('attemptsdeleted', 'mod_aiinteractivevideo', count($attempts)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    redirect($baseurl);
}
if ($action === 'regrade' && $canmanage) {
    require_sesskey();
    aiinteractivevideo_update_grades($instance);
    redirect($baseurl, get_string('regraded', 'mod_aiinteractivevideo'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$str = fn($k, $a = null) => get_string($k, 'mod_aiinteractivevideo', $a);
$sections = array_values(manager::get_sections($instance->id));

// Attempts in this mode (bounded to this activity; used by the KPIs, the table and the download).
$userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
$params = ['instanceid' => $instance->id, 'mode' => $mode];
$usersql = '';
if ($userids !== null) {
    if ($userids) {
        [$insql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $usersql = " AND a.userid $insql";
        $params += $uparams;
    } else {
        $usersql = ' AND 1 = 0';
    }
}
$sql = "SELECT a.id, a.userid, a.attempt, a.mode, a.state, a.score, a.maxscore, a.activetime, a.timestart, a.timefinish,
               u.email, $userfields,
               (SELECT COALESCE(SUM(r.hintsused), 0) FROM {aiinteractivevideo_response} r WHERE r.attemptid = a.id) AS hints,
               (SELECT COALESCE(SUM(r.revealed), 0) FROM {aiinteractivevideo_response} r WHERE r.attemptid = a.id) AS reveals,
               (SELECT COALESCE(SUM(r.rewatches), 0) FROM {aiinteractivevideo_response} r WHERE r.attemptid = a.id) AS rewatches
          FROM {aiinteractivevideo_attempt} a
          JOIN {user} u ON u.id = a.userid
         WHERE a.aiinteractivevideoid = :instanceid AND a.mode = :mode $usersql
      ORDER BY u.lastname, u.firstname, a.attempt";

if ($download && $tab === 'attempts') {
    $columns = [
        'fullname' => get_string('fullname'),
        'email' => get_string('email'),
        'mode' => $str('mode'),
        'attempt' => $str('attempt'),
        'state' => $str('status'),
        'score' => $str('points'),
        'maxscore' => $str('total'),
        'percent' => $str('percentage'),
        'activetime' => $str('activetime'),
        'hints' => $str('hints'),
        'reveals' => $str('revealed'),
        'rewatches' => $str('rewatches'),
        'started' => $str('started'),
        'finished' => $str('status_finished'),
    ];
    $rs = $DB->get_recordset_sql($sql, $params);
    $rows = new \core\dml\recordset_walk(
        $rs,
        function ($a) {
            return [
                'fullname' => fullname($a),
                'email' => $a->email,
                'mode' => $a->mode,
                'attempt' => $a->attempt,
                'state' => $a->state,
                'score' => $a->score,
                'maxscore' => $a->maxscore,
                'percent' => $a->maxscore ? round($a->score / $a->maxscore * 100, 1) : 0,
                'activetime' => transcript::clock($a->activetime),
                'hints' => $a->hints,
                'reveals' => $a->reveals,
                'rewatches' => $a->rewatches,
                'started' => userdate($a->timestart, get_string('strftimedatetimeshort', 'langconfig')),
                'finished' => $a->timefinish ? userdate($a->timefinish, get_string('strftimedatetimeshort', 'langconfig')) : '',
            ];
        }
    );
    \core\dataformat::download_data('aiinteractivevideo_' . $cm->id . '_' . $mode, $download, $columns, $rows);
    $rs->close();
    die();
}

// Tabs.
$tabs = [];
foreach (['insights' => $str('overview'), 'attempts' => $str('attempts')] as $key => $label) {
    $tabs[] = ['label' => $label, 'url' => (new moodle_url($baseurl, ['tab' => $key]))->out(false),
        'active' => $tab === $key || ($tab === 'attempt' && $key === 'attempts')];
}
$modetabs = [];
foreach ($modes as $m) {
    $modetabs[] = ['label' => $m === 'learn' ? $str('learnmode') : $str('testmode'),
        'url' => (new moodle_url($baseurl, ['mode' => $m]))->out(false), 'active' => $m === $mode];
}

$templatedata = [
    'tabs' => $tabs,
    'modetabs' => count($modetabs) > 1 ? $modetabs : [],
    'groupselector' => groups_print_activity_menu($cm, $baseurl, true),
    'insights' => $tab === 'insights',
    'attemptslist' => $tab === 'attempts',
    'attemptview' => $tab === 'attempt',
];

// KPI cards and per-interaction statistics.
$attempts = [];
$rs = $DB->get_recordset_sql($sql, $params);
foreach ($rs as $a) {
    $attempts[$a->id] = $a;
}
$rs->close();
$finished = array_filter($attempts, fn($a) => $a->state === manager::STATE_FINISHED);
$learners = count(array_unique(array_map(fn($a) => $a->userid, $attempts)));
$avgpct = $finished ? array_sum(array_map(fn($a) => $a->maxscore ? $a->score / $a->maxscore * 100 : 0, $finished))
    / count($finished) : 0;
$avgtime = $finished ? array_sum(array_map(fn($a) => $a->activetime, $finished)) / count($finished) : 0;
$templatedata['kpis'] = [
    ['label' => $str('learners'), 'value' => $learners, 'icon' => ['ic' => ['book' => true]]],
    ['label' => $str('attempts'), 'value' => count($finished) . ' / ' . count($attempts),
        'icon' => ['ic' => ['clipboard' => true]]],
    ['label' => $str('percentage'), 'value' => round($avgpct) . '%', 'icon' => ['ic' => ['star' => true]]],
    ['label' => $str('averagetime'), 'value' => transcript::clock($avgtime), 'icon' => ['ic' => ['clock' => true]]],
];

if ($tab === 'insights') {
    $stats = manager::section_stats($instance, $mode, $userids);
    $times = [];
    foreach ($stats as $s) {
        if ($s->answered > 0) {
            $times[] = $s->timespent / max(1, $s->answered);
        }
    }
    sort($times);
    $median = $times ? $times[intdiv(count($times), 2)] : 0;
    $rows = [];
    $hardest = null;
    foreach ($sections as $i => $section) {
        $s = $stats[$section->id] ?? null;
        $answered = $s ? (int)$s->answered : 0;
        $firstpct = $answered ? round($s->firsttry / $answered * 100) : null;
        $solvedpct = $answered ? round($s->solved / $answered * 100) : null;
        $avgtries = $answered ? round($s->tries / $answered, 1) : null;
        $avgsecs = $answered ? $s->timespent / $answered : 0;
        $avgrewatch = $answered ? round($s->rewatches / $answered, 1) : 0;
        $flags = [];
        if ($answered >= 1 && $firstpct !== null && $firstpct < 50) {
            $flags[] = ['text' => $str('flag_hard'), 'kind' => 'hard'];
        }
        if ($answered >= 1 && $median > 0 && $avgsecs > max(30, $median * 1.6)) {
            $flags[] = ['text' => $str('flag_slow'), 'kind' => 'slow'];
        }
        if ($answered >= 1 && $avgrewatch >= 1) {
            $flags[] = ['text' => $str('flag_rewatch'), 'kind' => 'rewatch'];
        }
        if ($firstpct !== null && ($hardest === null || $firstpct < $hardest['pct'])) {
            $hardest = ['pct' => $firstpct, 'title' => $section->title];
        }
        $rows[] = [
            'number' => $i + 1,
            'title' => format_string($section->title, true, ['context' => $context, 'escape' => false]),
            'typekey' => $section->type,
            'typename' => $str($section->type),
            'time' => transcript::clock((float)$section->starttime) . ' – ' . transcript::clock((float)$section->endtime),
            'answered' => $answered,
            'hasdata' => $answered > 0,
            'firstpct' => $firstpct ?? 0,
            'firstlevel' => $firstpct === null ? 'none' : ($firstpct >= 75 ? 'good' : ($firstpct >= 50 ? 'ok' : 'bad')),
            'solvedpct' => $solvedpct ?? 0,
            'avgtries' => $avgtries ?? '-',
            'avgtime' => transcript::clock($avgsecs),
            'avgrewatch' => $avgrewatch,
            'hints' => $s ? (int)$s->hints : 0,
            'reveals' => $s ? (int)$s->revealed : 0,
            'skipped' => $s ? (int)$s->skipped : 0,
            'flags' => $flags,
            'hasflags' => !empty($flags),
        ];
    }
    $templatedata['rows'] = $rows;
    $templatedata['hasrows'] = !empty($rows);
    $templatedata['learnmode'] = $mode === 'learn';
    $templatedata['hardest'] = $hardest && $hardest['pct'] < 100 ?
        format_string($hardest['title'], true, ['context' => $context, 'escape' => false]) . ' · ' . $hardest['pct'] . '%' : '';
}

if ($tab === 'attempts') {
    $list = [];
    foreach ($attempts as $a) {
        $pct = $a->maxscore ? round($a->score / $a->maxscore * 100) : 0;
        $list[] = [
            'id' => $a->id,
            'fullname' => fullname($a),
            'attempt' => $a->attempt,
            'finished' => $a->state === manager::STATE_FINISHED,
            'state' => $a->state === manager::STATE_FINISHED ? $str('status_finished') : $str('status_inprogress'),
            'score' => $a->score . ' / ' . $a->maxscore,
            'percent' => $pct,
            'level' => $pct >= 75 ? 'good' : ($pct >= 50 ? 'ok' : 'bad'),
            'time' => transcript::clock($a->activetime),
            'hints' => $a->hints,
            'reveals' => $a->reveals,
            'rewatches' => $a->rewatches,
            'started' => userdate($a->timestart, get_string('strftimedatetimeshort', 'langconfig')),
            'viewurl' => (new moodle_url($baseurl, ['tab' => 'attempt', 'attemptid' => $a->id]))->out(false),
        ];
    }
    $templatedata['attempts'] = $list;
    $templatedata['hasattempts'] = !empty($list);
    $templatedata['canmanage'] = $canmanage;
    $templatedata['actionurl'] = $baseurl->out(false);
    $templatedata['sesskey'] = sesskey();
    $templatedata['regradeurl'] = (new moodle_url($baseurl, ['action' => 'regrade', 'sesskey' => sesskey()]))->out(false);
    $templatedata['download'] = $list ? $OUTPUT->download_dataformat_selector(
        $str('download'),
        $baseurl->out_omit_querystring(),
        'download',
        $baseurl->params()
    ) : '';
}

if ($tab === 'attempt') {
    $attempt = $attempts[$attemptid] ?? null;
    if (!$attempt) {
        throw new moodle_exception('invalidrecord', 'error');
    }
    $responses = manager::responses_by_section((int)$attempt->id);
    $detail = [];
    foreach ($sections as $i => $section) {
        $r = $responses[$section->id] ?? null;
        $status = manager::status($r);
        $detail[] = [
            'number' => $i + 1,
            'title' => format_string($section->title, true, ['context' => $context, 'escape' => false]),
            'typekey' => $section->type,
            'typename' => $str($section->type),
            'status' => $status === 'pending' && $r && $r->tries ? $str('wrong') : $str(
                'status_' . (
                    $status === 'pending' ?
                    'notanswered' : $status
                )
            ),
            'ok' => $r && $r->solved,
            'points' => $r ? (int)$r->points : 0,
            'tries' => $r ? (int)$r->tries : 0,
            'time' => transcript::clock($r ? $r->timespent : 0),
            'hints' => $r ? (int)$r->hintsused : 0,
            'rewatches' => $r ? (int)$r->rewatches : 0,
        ];
    }
    $templatedata['detail'] = $detail;
    $templatedata['detailname'] = fullname($attempt);
    $templatedata['detailscore'] = $attempt->score . ' / ' . $attempt->maxscore;
    $templatedata['backurl'] = (new moodle_url($baseurl, ['tab' => 'attempts']))->out(false);
}

$PAGE->requires->js_call_amd('mod_aiinteractivevideo/report', 'init', ['.aiv-report']);
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_aiinteractivevideo/report', $templatedata);
echo $OUTPUT->footer();
