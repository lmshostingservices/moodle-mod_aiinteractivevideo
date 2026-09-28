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
 * Add or edit one interaction.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_aiinteractivevideo\local\interaction;
use mod_aiinteractivevideo\local\manager;
use mod_aiinteractivevideo\local\transcript;

$id = required_param('id', PARAM_INT);
$sectionid = optional_param('sectionid', 0, PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'aiinteractivevideo');
$instance = $DB->get_record('aiinteractivevideo', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aiinteractivevideo:manage', $context);

$section = $sectionid ? $DB->get_record(
    'aiinteractivevideo_section',
    ['id' => $sectionid, 'aiinteractivevideoid' => $instance->id],
    '*',
    MUST_EXIST
) : null;

$url = new moodle_url('/mod/aiinteractivevideo/editsection.php', ['id' => $cm->id, 'sectionid' => $sectionid]);
$returnurl = new moodle_url('/mod/aiinteractivevideo/edit.php', ['id' => $cm->id]);
$PAGE->set_url($url);
$title = get_string('editsection', 'mod_aiinteractivevideo');
$PAGE->set_title(format_string($instance->name) . ': ' . $title);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->set_attrs(['description' => '', 'hidecompletion' => true]);
$PAGE->navbar->add(get_string('editinteractions', 'mod_aiinteractivevideo'), $returnurl);
$PAGE->navbar->add($title);

$form = new \mod_aiinteractivevideo\form\section_form($url);
if ($section) {
    $content = interaction::content($section);
    $form->set_data(
        [
            'id' => $cm->id,
            'sectionid' => $section->id,
            'title' => $section->title,
            'starttime' => transcript::clock((float)$section->starttime),
            'endtime' => transcript::clock((float)$section->endtime),
            'type' => $section->type,
            'prompt' => $content['prompt'] ?? '',
            'content' => $content ? interaction::to_text($section->type, $content) : '',
            'hint' => $section->hint,
            'feedbackcorrect' => $section->feedbackcorrect,
            'feedbackwrong' => $section->feedbackwrong,
        ]
    );
} else {
    $last = $DB->get_field_sql(
        'SELECT MAX(endtime) FROM {aiinteractivevideo_section} WHERE aiinteractivevideoid = :id',
        ['id' => $instance->id]
    );
    $form->set_data(
        ['id' => $cm->id, 'sectionid' => 0, 'starttime' => transcript::clock((float)$last),
        'type' => 'cardselect']
    );
}

if ($form->is_cancelled()) {
    redirect($returnurl);
}
if ($data = $form->get_data()) {
    $content = interaction::from_text($data->type, $data->content, $data->prompt);
    $record = (object)[
        'title' => $data->title,
        'starttime' => transcript::parse_clock($data->starttime),
        'endtime' => transcript::parse_clock($data->endtime),
        'type' => $data->type,
        'content' => json_encode($content),
        'hint' => $data->hint,
        'feedbackcorrect' => $data->feedbackcorrect,
        'feedbackwrong' => $data->feedbackwrong,
        'timemodified' => time(),
    ];
    if ($section) {
        $record->id = $section->id;
        $DB->update_record('aiinteractivevideo_section', $record);
    } else {
        $record->aiinteractivevideoid = $instance->id;
        $record->sortorder = 0;
        $record->timecreated = $record->timemodified;
        $DB->insert_record('aiinteractivevideo_section', $record);
    }
    // Keep sections in time order.
    $all = $DB->get_records(
        'aiinteractivevideo_section',
        ['aiinteractivevideoid' => $instance->id],
        'starttime ASC, id ASC',
        'id'
    );
    $order = 1;
    foreach ($all as $row) {
        $DB->set_field('aiinteractivevideo_section', 'sortorder', $order++, ['id' => $row->id]);
    }
    manager::sync_grade($instance);
    redirect(
        $returnurl,
        get_string('sectionsaved', 'mod_aiinteractivevideo'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title, 3);
echo html_writer::start_div('aiv-editor aiv-editor-form');
$form->display();
echo html_writer::end_div();
echo $OUTPUT->footer();
