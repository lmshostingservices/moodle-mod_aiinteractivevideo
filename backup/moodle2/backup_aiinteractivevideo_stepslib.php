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
 * Backup structure for mod_aiinteractivevideo.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Backup structure step.
 */
class backup_aiinteractivevideo_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $root = new backup_nested_element('aiinteractivevideo', ['id'], [
            'name', 'intro', 'introformat', 'videourl', 'videoid', 'videoduration', 'transcript', 'numinteractions',
            'generator', 'genstatus', 'types', 'allowlearn', 'allowtest', 'preventskip', 'sounds', 'leaderboard', 'accent',
            'maxtries', 'scoremode', 'grade', 'grademethod', 'maxattempts', 'completionfinish', 'timecreated', 'timemodified',
        ]);
        $sections = new backup_nested_element('sections');
        $section = new backup_nested_element('section', ['id'], [
            'sortorder', 'title', 'starttime', 'endtime', 'type', 'content', 'hint', 'feedbackcorrect', 'feedbackwrong',
            'timecreated', 'timemodified',
        ]);
        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid', 'attempt', 'mode', 'state', 'salt', 'score', 'maxscore', 'position', 'maxwatched', 'activetime',
            'timestart', 'timefinish', 'timemodified',
        ]);
        $responses = new backup_nested_element('responses');
        $response = new backup_nested_element('response', ['id'], [
            'sectionid', 'tries', 'solved', 'firsttry', 'points', 'hintsused', 'revealed', 'skipped', 'rewatches',
            'timespent', 'response', 'timecreated', 'timemodified',
        ]);

        $root->add_child($sections);
        $sections->add_child($section);
        $root->add_child($attempts);
        $attempts->add_child($attempt);
        $attempt->add_child($responses);
        $responses->add_child($response);

        $root->set_source_table('aiinteractivevideo', ['id' => backup::VAR_ACTIVITYID]);
        $section->set_source_table(
            'aiinteractivevideo_section',
            ['aiinteractivevideoid' => backup::VAR_PARENTID],
            'sortorder ASC'
        );
        if ($userinfo) {
            $attempt->set_source_table(
                'aiinteractivevideo_attempt',
                ['aiinteractivevideoid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $response->set_source_table('aiinteractivevideo_response', ['attemptid' => backup::VAR_PARENTID], 'id ASC');
        }
        $attempt->annotate_ids('user', 'userid');
        $root->annotate_files('mod_aiinteractivevideo', 'intro', null);

        return $this->prepare_activity_structure($root);
    }
}
