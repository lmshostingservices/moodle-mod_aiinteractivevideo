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
 * Library of interface functions and constants for mod_aiinteractivevideo.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Grade method: highest attempt. */
define('MOD_AIINTERACTIVEVIDEO_GRADEHIGHEST', 1);
/** Grade method: average of attempts. */
define('MOD_AIINTERACTIVEVIDEO_GRADEAVERAGE', 2);
/** Grade method: first attempt. */
define('MOD_AIINTERACTIVEVIDEO_GRADEFIRST', 3);
/** Grade method: last attempt. */
define('MOD_AIINTERACTIVEVIDEO_GRADELAST', 4);

/**
 * Declares the features this module supports.
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed
 */
function aiinteractivevideo_supports($feature) {
    if (defined('FEATURE_MOD_OTHERPURPOSE') && $feature === FEATURE_MOD_OTHERPURPOSE) {
        return MOD_PURPOSE_INTERACTIVECONTENT;
    }
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_OTHER;
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Normalises form data before saving.
 *
 * @param stdClass $data
 * @return stdClass
 */
function mod_aiinteractivevideo_prepare_instance_data(stdClass $data): stdClass {
    foreach (['allowlearn', 'allowtest', 'preventskip', 'sounds', 'leaderboard', 'completionfinish'] as $flag) {
        $data->$flag = empty($data->$flag) ? 0 : 1;
    }
    if (empty($data->allowlearn) && empty($data->allowtest)) {
        $data->allowlearn = 1;
    }
    if (isset($data->videourl)) {
        $data->videourl = trim($data->videourl);
        $data->videoid = (string)\mod_aiinteractivevideo\local\youtube::video_id($data->videourl);
    }
    if (isset($data->typeselect) && is_array($data->typeselect)) {
        $types = array_keys(array_filter($data->typeselect));
        $types = array_values(array_intersect(\mod_aiinteractivevideo\local\interaction::TYPES, $types));
        $data->types = implode(',', $types ?: \mod_aiinteractivevideo\local\interaction::TYPES);
    }
    if (isset($data->numinteractions)) {
        $data->numinteractions = max(1, min(20, (int)$data->numinteractions));
    }
    if (isset($data->accent) && !array_key_exists($data->accent, mod_aiinteractivevideo_accents())) {
        $data->accent = 'indigo';
    }
    return $data;
}

/**
 * Accent colours offered to teachers (key => hex).
 *
 * @return array
 */
function mod_aiinteractivevideo_accents(): array {
    return [
        'indigo' => '#4f46e5',
        'violet' => '#7c3aed',
        'blue' => '#2563eb',
        'teal' => '#0d9488',
        'emerald' => '#059669',
        'rose' => '#e11d48',
        'amber' => '#d97706',
    ];
}

/**
 * Adds a new instance and builds its interactions.
 *
 * @param stdClass $data
 * @param mod_aiinteractivevideo_mod_form|null $mform
 * @return int new instance id
 */
function aiinteractivevideo_add_instance($data, $mform = null) {
    global $DB, $USER;
    $data = mod_aiinteractivevideo_prepare_instance_data($data);
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->genstatus = 0;
    $data->grade = 0;
    $data->id = $DB->insert_record('aiinteractivevideo', $data);
    $context = context_module::instance($data->coursemodule);
    \mod_aiinteractivevideo\local\manager::build_after_save($data->id, $context, (int)$USER->id);
    $instance = $DB->get_record('aiinteractivevideo', ['id' => $data->id], '*', MUST_EXIST);
    $instance->cmidnumber = $data->cmidnumber ?? '';
    aiinteractivevideo_grade_item_update($instance);
    if (!empty($data->completionexpected)) {
        \core_completion\api::update_completion_date_event(
            $data->coursemodule,
            'aiinteractivevideo',
            $data->id,
            $data->completionexpected
        );
    }
    return $data->id;
}

/**
 * Updates an instance; rebuilds interactions when asked to.
 *
 * @param stdClass $data
 * @param mod_aiinteractivevideo_mod_form|null $mform
 * @return bool
 */
function aiinteractivevideo_update_instance($data, $mform = null) {
    global $DB, $USER;
    $data = mod_aiinteractivevideo_prepare_instance_data($data);
    $data->id = $data->instance;
    $data->timemodified = time();
    $old = $DB->get_record('aiinteractivevideo', ['id' => $data->id], '*', MUST_EXIST);
    if ($old->videoid !== ($data->videoid ?? $old->videoid)) {
        // A different video: the stored length no longer applies.
        $data->videoduration = 0;
    }
    unset($data->grade);
    $DB->update_record('aiinteractivevideo', $data);
    if (!empty($data->rebuild)) {
        $context = context_module::instance($data->coursemodule);
        \mod_aiinteractivevideo\local\manager::build_after_save($data->id, $context, (int)$USER->id);
    }
    $instance = $DB->get_record('aiinteractivevideo', ['id' => $data->id], '*', MUST_EXIST);
    $instance->cmidnumber = $data->cmidnumber ?? '';
    aiinteractivevideo_grade_item_update($instance);
    aiinteractivevideo_update_grades($instance, 0, false);
    \core_completion\api::update_completion_date_event(
        $data->coursemodule,
        'aiinteractivevideo',
        $data->id,
        $data->completionexpected ?? null
    );
    return true;
}

/**
 * Deletes an instance and all its data.
 *
 * @param int $id
 * @return bool
 */
function aiinteractivevideo_delete_instance($id) {
    global $DB;
    if (!$instance = $DB->get_record('aiinteractivevideo', ['id' => $id])) {
        return false;
    }
    $attemptids = $DB->get_fieldset_select('aiinteractivevideo_attempt', 'id', 'aiinteractivevideoid = :id', ['id' => $id]);
    if ($attemptids) {
        [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('aiinteractivevideo_response', "attemptid $insql", $params);
    }
    $DB->delete_records('aiinteractivevideo_attempt', ['aiinteractivevideoid' => $id]);
    $DB->delete_records('aiinteractivevideo_section', ['aiinteractivevideoid' => $id]);
    aiinteractivevideo_grade_item_delete($instance);
    $DB->delete_records('aiinteractivevideo', ['id' => $id]);
    \mod_aiinteractivevideo\local\manager::forget_generation((int)$id);
    return true;
}

/**
 * Adds completion rule data to the course module info cache.
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|false
 */
function aiinteractivevideo_get_coursemodule_info($coursemodule) {
    global $DB;
    $fields = 'id, name, intro, introformat, completionfinish';
    if (!$instance = $DB->get_record('aiinteractivevideo', ['id' => $coursemodule->instance], $fields)) {
        return false;
    }
    $result = new cached_cm_info();
    $result->name = $instance->name;
    if ($coursemodule->showdescription) {
        $result->content = format_module_intro('aiinteractivevideo', $instance, $coursemodule->id, false);
    }
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionfinish'] = $instance->completionfinish;
    }
    return $result;
}

/**
 * Creates or updates the grade item. The maximum grade is the number of interactions (one point each).
 *
 * @param stdClass $instance
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int GRADE_UPDATE_OK etc.
 */
function aiinteractivevideo_grade_item_update($instance, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $params = ['itemname' => $instance->name];
    if (isset($instance->cmidnumber) && $instance->cmidnumber !== '') {
        $params['idnumber'] = $instance->cmidnumber;
    }
    if ($instance->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $instance->grade;
        $params['grademin'] = 0;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }
    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }
    return grade_update(
        'mod/aiinteractivevideo',
        $instance->course,
        'mod',
        'aiinteractivevideo',
        $instance->id,
        0,
        $grades,
        $params
    );
}

/**
 * Deletes the grade item.
 *
 * @param stdClass $instance
 * @return int
 */
function aiinteractivevideo_grade_item_delete($instance) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update(
        'mod/aiinteractivevideo',
        $instance->course,
        'mod',
        'aiinteractivevideo',
        $instance->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * Returns user grades (points) according to the grading method.
 *
 * @param stdClass $instance
 * @param int $userid 0 = all users
 * @return array userid => stdClass(userid, rawgrade)
 */
function aiinteractivevideo_get_user_grades($instance, $userid = 0) {
    return \mod_aiinteractivevideo\local\manager::user_grades($instance, (int)$userid);
}

/**
 * Pushes grades to the gradebook.
 *
 * @param stdClass $instance
 * @param int $userid
 * @param bool $nullifnone
 */
function aiinteractivevideo_update_grades($instance, $userid = 0, $nullifnone = true) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    if ($instance->grade <= 0) {
        aiinteractivevideo_grade_item_update($instance);
        return;
    }
    if ($grades = aiinteractivevideo_get_user_grades($instance, $userid)) {
        aiinteractivevideo_grade_item_update($instance, $grades);
    } else if ($userid && $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        aiinteractivevideo_grade_item_update($instance, $grade);
    } else {
        aiinteractivevideo_grade_item_update($instance);
    }
}

/**
 * Marks the activity viewed and triggers the event.
 *
 * @param stdClass $instance
 * @param stdClass $course
 * @param stdClass|cm_info $cm
 * @param context_module $context
 */
function aiinteractivevideo_view($instance, $course, $cm, $context) {
    global $CFG;
    require_once($CFG->libdir . '/completionlib.php');
    $event = \mod_aiinteractivevideo\event\course_module_viewed::create([
        'objectid' => $instance->id,
        'context' => $context,
    ]);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('aiinteractivevideo', $instance);
    $event->trigger();
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Adds Edit interactions and Reports to the activity's secondary navigation.
 *
 * @param settings_navigation $settingsnav
 * @param navigation_node $node
 */
function aiinteractivevideo_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $node) {
    $cm = $settingsnav->get_page()->cm;
    if (!$cm) {
        return;
    }
    $context = context_module::instance($cm->id);
    if (has_capability('mod/aiinteractivevideo:manage', $context)) {
        $node->add(
            get_string('editinteractions', 'mod_aiinteractivevideo'),
            new moodle_url('/mod/aiinteractivevideo/edit.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'aiinteractivevideo_edit'
        );
    }
    if (has_capability('mod/aiinteractivevideo:viewreports', $context)) {
        $node->add(
            get_string('reports', 'mod_aiinteractivevideo'),
            new moodle_url('/mod/aiinteractivevideo/report.php', ['id' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'aiinteractivevideo_reports'
        );
    }
}

/**
 * Course reset form elements.
 *
 * @param MoodleQuickForm $mform
 */
function aiinteractivevideo_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'aiinteractivevideoheader', get_string('modulenameplural', 'mod_aiinteractivevideo'));
    $mform->addElement(
        'advcheckbox',
        'reset_aiinteractivevideo_attempts',
        get_string('resetattempts', 'mod_aiinteractivevideo')
    );
}

/**
 * Course reset defaults.
 *
 * @param stdClass $course
 * @return array
 */
function aiinteractivevideo_reset_course_form_defaults($course) {
    return ['reset_aiinteractivevideo_attempts' => 1];
}

/**
 * Resets user data in a course.
 *
 * @param stdClass $data
 * @return array status
 */
function aiinteractivevideo_reset_userdata($data) {
    global $DB;
    $status = [];
    if (!empty($data->reset_aiinteractivevideo_attempts)) {
        $instances = $DB->get_records('aiinteractivevideo', ['course' => $data->courseid]);
        foreach ($instances as $instance) {
            $attemptids = $DB->get_fieldset_select(
                'aiinteractivevideo_attempt',
                'id',
                'aiinteractivevideoid = :id',
                ['id' => $instance->id]
            );
            if ($attemptids) {
                [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('aiinteractivevideo_response', "attemptid $insql", $params);
            }
            $DB->delete_records('aiinteractivevideo_attempt', ['aiinteractivevideoid' => $instance->id]);
            aiinteractivevideo_grade_item_update($instance, 'reset');
        }
        $status[] = [
            'component' => get_string('modulenameplural', 'mod_aiinteractivevideo'),
            'item' => get_string('resetattempts', 'mod_aiinteractivevideo'),
            'error' => false,
        ];
    }
    return $status;
}

/**
 * Lists the types of events that can be reset.
 *
 * @return array
 */
function aiinteractivevideo_get_view_actions() {
    return ['view', 'view all'];
}

/**
 * Lists post actions.
 *
 * @return array
 */
function aiinteractivevideo_get_post_actions() {
    return ['attempt'];
}
