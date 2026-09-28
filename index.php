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
 * Lists the AI interactive videos in a course.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($course->id);
require_capability('mod/aiinteractivevideo:view', $context);

\mod_aiinteractivevideo\event\course_module_instance_list_viewed::create(['context' => $context])->trigger();

$PAGE->set_url('/mod/aiinteractivevideo/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->shortname) . ': ' . get_string('modulenameplural', 'mod_aiinteractivevideo'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');
$PAGE->navbar->add(get_string('modulenameplural', 'mod_aiinteractivevideo'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_aiinteractivevideo'));

$instances = get_all_instances_in_course('aiinteractivevideo', $course);
if (!$instances) {
    notice(
        get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_aiinteractivevideo')),
        new moodle_url('/course/view.php', ['id' => $course->id])
    );
}

$table = new html_table();
$table->head = [get_string('name'), get_string('interactions', 'mod_aiinteractivevideo')];
foreach ($instances as $instance) {
    $link = html_writer::link(
        new moodle_url('/mod/aiinteractivevideo/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name, true, ['context' => $context]),
        $instance->visible ? [] : ['class' => 'dimmed']
    );
    $count = $DB->count_records('aiinteractivevideo_section', ['aiinteractivevideoid' => $instance->id]);
    $table->data[] = [$link, $count];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
