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
 * Interaction builder: review, edit, rebuild and import the interactions.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_aiinteractivevideo\local\lmslabs;
use mod_aiinteractivevideo\local\interaction;
use mod_aiinteractivevideo\local\manager;
use mod_aiinteractivevideo\local\transcript;
use mod_aiinteractivevideo\local\youtube;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'aiinteractivevideo');
$instance = $DB->get_record('aiinteractivevideo', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aiinteractivevideo:manage', $context);

$url = new moodle_url('/mod/aiinteractivevideo/edit.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('editinteractions', 'mod_aiinteractivevideo'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->set_attrs(['description' => '', 'hidecompletion' => true]);
if ($node = $PAGE->settingsnav->find('aiinteractivevideo_edit', navigation_node::TYPE_SETTING)) {
    $node->make_active();
}

if ($action === 'delete') {
    require_sesskey();
    $sectionid = required_param('sectionid', PARAM_INT);
    $section = $DB->get_record(
        'aiinteractivevideo_section',
        ['id' => $sectionid, 'aiinteractivevideoid' => $instance->id],
        '*',
        MUST_EXIST
    );
    $DB->delete_records('aiinteractivevideo_response', ['sectionid' => $section->id]);
    $DB->delete_records('aiinteractivevideo_section', ['id' => $section->id]);
    manager::sync_grade($instance);
    redirect($url, get_string('sectiondeleted', 'mod_aiinteractivevideo'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$sections = array_values(manager::get_sections($instance->id));
$cues = transcript::parse((string)$instance->transcript, (int)$instance->videoduration);
$duration = (int)$instance->videoduration ?: (int)ceil($sections ? end($sections)->endtime : transcript::estimated_end($cues));
$rows = [];
foreach ($sections as $i => $section) {
    $content = interaction::content($section);
    $rows[] = [
        'number' => $i + 1,
        'id' => $section->id,
        'title' => format_string($section->title, true, ['context' => $context, 'escape' => false]),
        'start' => transcript::clock((float)$section->starttime),
        'end' => transcript::clock((float)$section->endtime),
        'typekey' => $section->type,
        'typename' => get_string($section->type, 'mod_aiinteractivevideo'),
        'prompt' => $content['prompt'] ?? '',
        'content' => $content ? interaction::to_text($section->type, $content) : '',
        'hint' => $section->hint,
        'feedbackcorrect' => $section->feedbackcorrect,
        'feedbackwrong' => $section->feedbackwrong,
        'left' => $duration ? round($section->starttime / $duration * 100, 2) : 0,
        'width' => $duration ? max(1, round(($section->endtime - $section->starttime) / $duration * 100, 2)) : 0,
        'editurl' => (
            new moodle_url(
                '/mod/aiinteractivevideo/editsection.php',
                ['id' => $cm->id, 'sectionid' => $section->id]
            )
        )->out(false),
        'deleteurl' => (
            new moodle_url(
                $url,
                ['action' => 'delete', 'sectionid' => $section->id,
                'sesskey' => sesskey()]
            )
        )->out(false),
    ];
}

$failed = (int)$instance->genstatus === manager::GEN_FAILED;
$error = $failed ? manager::generation_error($instance->id) : null;
$templatedata = [
    'cmid' => $cm->id,
    'name' => format_string($instance->name, true, ['context' => $context, 'escape' => false]),
    'thumbnail' => $instance->videoid ? youtube::thumbnail($instance->videoid) : '',
    'count' => count($sections),
    'duration' => transcript::clock($duration),
    'transcriptlines' => count($cues),
    'sections' => $rows,
    'hassections' => !empty($rows),
    'viewurl' => (new moodle_url('/mod/aiinteractivevideo/view.php', ['id' => $cm->id]))->out(false),
    'settingsurl' => (new moodle_url('/course/modedit.php', ['update' => $cm->id]))->out(false),
    'addurl' => (new moodle_url('/mod/aiinteractivevideo/editsection.php', ['id' => $cm->id]))->out(false),
    'configured' => lmslabs::configured(),
    'cost' => lmslabs::COST,
    'failed' => $failed,
    'failmessage' => $error['message'] ?? get_string('aistatusfailed', 'mod_aiinteractivevideo'),
    'buyurl' => $error['buyurl'] ?? '',
    'pending' => (int)$instance->genstatus === manager::GEN_PENDING,
];

$PAGE->requires->js_call_amd('mod_aiinteractivevideo/builder', 'init', ['#aiv-editor-' . $cm->id, (int)$cm->id]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_aiinteractivevideo/editor', $templatedata);
echo $OUTPUT->footer();
