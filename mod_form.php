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
 * Activity settings form.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/aiinteractivevideo/lib.php');

use mod_aiinteractivevideo\local\lmslabs;
use mod_aiinteractivevideo\local\manager;
use mod_aiinteractivevideo\local\interaction;
use mod_aiinteractivevideo\local\segmenter;
use mod_aiinteractivevideo\local\transcript;
use mod_aiinteractivevideo\local\youtube;

/**
 * Activity settings form.
 */
class mod_aiinteractivevideo_mod_form extends moodleform_mod {
    /**
     * Form definition.
     */
    public function definition() {
        global $USER;
        $mform = $this->_form;
        $config = get_config('mod_aiinteractivevideo');
        $editing = !empty($this->_instance);

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $this->standard_intro_elements();

        // Video and transcript.
        $mform->addElement('header', 'videohdr', get_string('videourl', 'mod_aiinteractivevideo'));
        $mform->setExpanded('videohdr');
        $mform->addElement(
            'text',
            'videourl',
            get_string('videourl', 'mod_aiinteractivevideo'),
            ['size' => '64',
            'placeholder' => 'https://www.youtube.com/watch?v=…']
        );
        $mform->setType('videourl', PARAM_URL);
        $mform->addRule('videourl', null, 'required', null, 'client');
        $mform->addHelpButton('videourl', 'videourl', 'mod_aiinteractivevideo');

        $mform->addElement(
            'textarea',
            'transcript',
            get_string('transcript', 'mod_aiinteractivevideo'),
            ['rows' => 12, 'cols' => 80, 'class' => 'aiv-transcript-input']
        );
        $mform->setType('transcript', PARAM_TEXT);
        $mform->addRule('transcript', null, 'required', null, 'client');
        $mform->addHelpButton('transcript', 'transcript', 'mod_aiinteractivevideo');

        $counts = [];
        for ($i = 1; $i <= 20; $i++) {
            $counts[$i] = $i;
        }
        $mform->addElement('select', 'numinteractions', get_string('numinteractions', 'mod_aiinteractivevideo'), $counts);
        $mform->setDefault('numinteractions', $config->defaultinteractions ?? 5);
        $mform->addHelpButton('numinteractions', 'numinteractions', 'mod_aiinteractivevideo');

        // Interactions are always created by LMS Labs AI; the cost is shown before the teacher saves.
        $mform->addElement('hidden', 'generator', 'ai');
        $mform->setType('generator', PARAM_ALPHA);
        $mform->addElement('static', 'lmslabs', get_string('lmslabs', 'mod_aiinteractivevideo'), $this->lmslabs_note());

        $typeboxes = [];
        foreach (interaction::TYPES as $type) {
            $typeboxes[] = $mform->createElement('advcheckbox', $type, '', get_string($type, 'mod_aiinteractivevideo'));
        }
        $mform->addGroup($typeboxes, 'typeselect', get_string('interactiontypes', 'mod_aiinteractivevideo'), ' ', true);
        foreach (interaction::TYPES as $type) {
            $mform->setDefault('typeselect[' . $type . ']', 1);
        }
        $mform->addHelpButton('typeselect', 'interactiontypes', 'mod_aiinteractivevideo');

        if ($editing) {
            $mform->addElement(
                'advcheckbox',
                'rebuild',
                get_string('rebuild', 'mod_aiinteractivevideo'),
                get_string('rebuild_desc', 'mod_aiinteractivevideo')
            );
            $mform->addHelpButton('rebuild', 'rebuild', 'mod_aiinteractivevideo');
        }

        // Modes.
        $mform->addElement('header', 'modeshdr', get_string('modes', 'mod_aiinteractivevideo'));
        $mform->setExpanded('modeshdr');
        $mform->addElement(
            'advcheckbox',
            'allowlearn',
            get_string('allowlearn', 'mod_aiinteractivevideo'),
            get_string('allowlearn_desc', 'mod_aiinteractivevideo')
        );
        $mform->setDefault('allowlearn', 1);
        $mform->addHelpButton('allowlearn', 'allowlearn', 'mod_aiinteractivevideo');
        $mform->addElement(
            'advcheckbox',
            'allowtest',
            get_string('allowtest', 'mod_aiinteractivevideo'),
            get_string('allowtest_desc', 'mod_aiinteractivevideo')
        );
        $mform->setDefault('allowtest', 1);
        $mform->addHelpButton('allowtest', 'allowtest', 'mod_aiinteractivevideo');

        $tries = [0 => get_string('unlimited')];
        for ($i = 1; $i <= 5; $i++) {
            $tries[$i] = $i;
        }
        $mform->addElement('select', 'maxtries', get_string('maxtries', 'mod_aiinteractivevideo'), $tries);
        $mform->setDefault('maxtries', 3);
        $mform->addHelpButton('maxtries', 'maxtries', 'mod_aiinteractivevideo');
        $mform->hideIf('maxtries', 'allowtest', 'notchecked');

        $mform->addElement(
            'select',
            'scoremode',
            get_string('scoremode', 'mod_aiinteractivevideo'),
            [
                1 => get_string('firsttryonly', 'mod_aiinteractivevideo'),
                2 => get_string('anytry', 'mod_aiinteractivevideo'),
            ]
        );
        $mform->setDefault('scoremode', 1);
        $mform->addHelpButton('scoremode', 'scoremode', 'mod_aiinteractivevideo');

        // Player.
        $mform->addElement('header', 'playerhdr', get_string('appearance'));
        $mform->addElement('advcheckbox', 'preventskip', get_string('preventskip', 'mod_aiinteractivevideo'));
        $mform->setDefault('preventskip', 1);
        $mform->addHelpButton('preventskip', 'preventskip', 'mod_aiinteractivevideo');
        $mform->addElement(
            'advcheckbox',
            'sounds',
            get_string('sounds', 'mod_aiinteractivevideo'),
            get_string('sounds_desc', 'mod_aiinteractivevideo')
        );
        $mform->setDefault('sounds', $config->defaultsounds ?? 1);
        $mform->addElement(
            'advcheckbox',
            'leaderboard',
            get_string('leaderboard', 'mod_aiinteractivevideo'),
            get_string('leaderboard_desc', 'mod_aiinteractivevideo')
        );
        $mform->setDefault('leaderboard', $config->defaultleaderboard ?? 1);
        $mform->addHelpButton('leaderboard', 'leaderboard', 'mod_aiinteractivevideo');
        $accents = [];
        foreach (array_keys(mod_aiinteractivevideo_accents()) as $key) {
            $accents[$key] = get_string('accent_' . $key, 'mod_aiinteractivevideo');
        }
        $mform->addElement('select', 'accent', get_string('accent', 'mod_aiinteractivevideo'), $accents);
        $mform->setDefault('accent', 'indigo');
        $mform->addHelpButton('accent', 'accent', 'mod_aiinteractivevideo');

        // Grade: the maximum is the number of interactions, so the teacher does not set it.
        $this->standard_grading_coursemodule_elements();
        if ($mform->elementExists('grade')) {
            $mform->removeElement('grade');
        }
        $current = isset($this->current->grade) ? (float)$this->current->grade : 0;
        $mform->addElement('hidden', 'grade', $current);
        $mform->setType('grade', PARAM_FLOAT);
        $mform->addElement(
            'select',
            'grademethod',
            get_string('grademethod', 'mod_aiinteractivevideo'),
            [
                MOD_AIINTERACTIVEVIDEO_GRADEHIGHEST => get_string('gradehighest', 'mod_aiinteractivevideo'),
                MOD_AIINTERACTIVEVIDEO_GRADEAVERAGE => get_string('gradeaverage', 'mod_aiinteractivevideo'),
                MOD_AIINTERACTIVEVIDEO_GRADEFIRST => get_string('gradefirst', 'mod_aiinteractivevideo'),
                MOD_AIINTERACTIVEVIDEO_GRADELAST => get_string('gradelast', 'mod_aiinteractivevideo'),
            ]
        );
        $mform->addHelpButton('grademethod', 'grademethod', 'mod_aiinteractivevideo');
        $attempts = [0 => get_string('unlimited')];
        for ($i = 1; $i <= 10; $i++) {
            $attempts[$i] = $i;
        }
        $mform->addElement('select', 'maxattempts', get_string('maxattempts', 'mod_aiinteractivevideo'), $attempts);

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Prepares stored values for the form.
     *
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        if (!empty($defaultvalues['types'])) {
            $allowed = explode(',', $defaultvalues['types']);
            foreach (interaction::TYPES as $type) {
                $defaultvalues['typeselect'][$type] = in_array($type, $allowed, true) ? 1 : 0;
            }
        }
    }

    /**
     * Returns the form element name with the completion suffix (Moodle 4.3+).
     *
     * @param string $name
     * @return string
     */
    protected function suffixed(string $name): string {
        return method_exists($this, 'get_suffix') ? $name . $this->get_suffix() : $name;
    }

    /**
     * Adds custom completion rules.
     *
     * @return array element names
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $name = $this->suffixed('completionfinish');
        $mform->addElement(
            'advcheckbox',
            $name,
            get_string('completionfinish', 'mod_aiinteractivevideo'),
            get_string('completionfinish_desc', 'mod_aiinteractivevideo')
        );
        $mform->addHelpButton($name, 'completionfinish', 'mod_aiinteractivevideo');
        return [$name];
    }

    /**
     * Whether a custom completion rule is enabled.
     *
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data[$this->suffixed('completionfinish')]);
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array errors
     */
    public function validation($data, $files) {
        // The maximum grade is the number of interactions: validate the grade to pass against that.
        $willrebuild = empty($this->_instance) || !empty($data['rebuild']);
        $data['grade'] = $willrebuild ? (int)($data['numinteractions'] ?? 0) : (float)($this->current->grade ?? 0);
        $errors = parent::validation($data, $files);
        if (empty($data['allowlearn']) && empty($data['allowtest'])) {
            $errors['allowtest'] = get_string('errornomode', 'mod_aiinteractivevideo');
        }
        if (youtube::video_id((string)($data['videourl'] ?? '')) === null) {
            $errors['videourl'] = get_string('erroryoutube', 'mod_aiinteractivevideo');
        }
        $units = segmenter::units(transcript::parse((string)($data['transcript'] ?? '')));
        if (count($units) < 2) {
            $errors['transcript'] = get_string('errortranscript', 'mod_aiinteractivevideo');
        }
        if ($willrebuild && !lmslabs::configured()) {
            $errors['numinteractions'] = get_string('lmslabs_notconfigured', 'mod_aiinteractivevideo');
        } else if (!empty($this->_instance) && !empty($data['rebuild']) && manager::generation_key((int)$this->_instance) !== '') {
            $errors['rebuild'] = get_string('lmslabs_unresolved', 'mod_aiinteractivevideo');
        }
        return $errors;
    }

    /**
     * What creating the activities costs, with the site's LMS Labs AI credits (advisory).
     *
     * @return string HTML
     */
    private function lmslabs_note(): string {
        if (!lmslabs::configured()) {
            return html_writer::div(get_string('lmslabs_notconfigured', 'mod_aiinteractivevideo'), 'alert alert-warning');
        }
        $lines = [get_string('lmslabs_cost', 'mod_aiinteractivevideo', lmslabs::COST)];
        $balance = lmslabs::balance();
        if ($balance && $balance['unlimited']) {
            $lines[] = get_string('lmslabs_balance_unlimited', 'mod_aiinteractivevideo');
        } else if ($balance && $balance['credits'] !== null) {
            $lines[] = get_string('lmslabs_balance', 'mod_aiinteractivevideo', $balance['credits']);
        }
        return html_writer::div(implode('<br>', array_map('s', $lines)), 'aiv-lmslabs-note');
    }
}
