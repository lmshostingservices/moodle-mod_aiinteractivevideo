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

namespace mod_aiinteractivevideo\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use mod_aiinteractivevideo\local\interaction;
use mod_aiinteractivevideo\local\transcript;

/**
 * Edit one section and its interaction.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_form extends \moodleform {
    /**
     * Definition.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'sectionid');
        $mform->setType('sectionid', PARAM_INT);

        $mform->addElement('text', 'title', get_string('title', 'mod_aiinteractivevideo'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addRule('title', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $times = [];
        $times[] = $mform->createElement(
            'text',
            'starttime',
            get_string('starttime', 'mod_aiinteractivevideo'),
            ['size' => 8, 'placeholder' => '0:00']
        );
        $times[] = $mform->createElement(
            'text',
            'endtime',
            get_string('end', 'mod_aiinteractivevideo'),
            ['size' => 8, 'placeholder' => '1:23']
        );
        $mform->addGroup(
            $times,
            'times',
            get_string('starttime', 'mod_aiinteractivevideo') . ' – ' .
            get_string('end', 'mod_aiinteractivevideo'),
            ' – ',
            false
        );
        $mform->setType('starttime', PARAM_TEXT);
        $mform->setType('endtime', PARAM_TEXT);
        $mform->addHelpButton('times', 'end', 'mod_aiinteractivevideo');

        $types = [];
        foreach (interaction::TYPES as $type) {
            $types[$type] = get_string($type, 'mod_aiinteractivevideo');
        }
        $mform->addElement('select', 'type', get_string('type', 'mod_aiinteractivevideo'), $types);

        $mform->addElement('text', 'prompt', get_string('prompt', 'mod_aiinteractivevideo'), ['size' => 80]);
        $mform->setType('prompt', PARAM_TEXT);
        $mform->addRule('prompt', null, 'required', null, 'client');
        $mform->addHelpButton('prompt', 'prompt', 'mod_aiinteractivevideo');

        $mform->addElement(
            'textarea',
            'content',
            get_string('content', 'mod_aiinteractivevideo'),
            ['rows' => 9, 'cols' => 80, 'class' => 'aiv-mono']
        );
        $mform->setType('content', PARAM_TEXT);
        $mform->addRule('content', null, 'required', null, 'client');
        $mform->addHelpButton('content', 'content', 'mod_aiinteractivevideo');
        foreach (interaction::TYPES as $type) {
            $name = 'format_' . $type;
            $mform->addElement(
                'static',
                $name,
                '',
                \html_writer::div(
                    s(
                        get_string(
                            $type . '_help',
                            'mod_aiinteractivevideo'
                        )
                    ),
                    'aiv-format-help'
                )
            );
            $mform->hideIf($name, 'type', 'neq', $type);
        }

        $mform->addElement('textarea', 'hint', get_string('hint', 'mod_aiinteractivevideo'), ['rows' => 2, 'cols' => 80]);
        $mform->setType('hint', PARAM_TEXT);
        $mform->addHelpButton('hint', 'hint', 'mod_aiinteractivevideo');
        $mform->addElement(
            'textarea',
            'feedbackcorrect',
            get_string('feedbackcorrect', 'mod_aiinteractivevideo'),
            ['rows' => 2, 'cols' => 80]
        );
        $mform->setType('feedbackcorrect', PARAM_TEXT);
        $mform->addElement(
            'textarea',
            'feedbackwrong',
            get_string('feedbackwrong', 'mod_aiinteractivevideo'),
            ['rows' => 2, 'cols' => 80]
        );
        $mform->setType('feedbackwrong', PARAM_TEXT);
        $mform->addHelpButton('feedbackwrong', 'feedbackwrong', 'mod_aiinteractivevideo');

        $this->add_action_buttons();
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $start = transcript::parse_clock((string)$data['starttime']);
        $end = transcript::parse_clock((string)$data['endtime']);
        if ($start === null || $end === null) {
            $errors['times'] = get_string('errortime', 'mod_aiinteractivevideo');
        } else if ($end <= $start) {
            $errors['times'] = get_string('errorend', 'mod_aiinteractivevideo');
        }
        if (!interaction::from_text((string)$data['type'], (string)$data['content'], (string)$data['prompt'])) {
            $errors['content'] = get_string('errorcontent', 'mod_aiinteractivevideo');
        }
        return $errors;
    }
}
