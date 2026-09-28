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
 * Site defaults for mod_aiinteractivevideo.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ADMIN->add('modsettings', new admin_externalpage(
    'mod_aiinteractivevideo_activation',
    get_string('act_title', 'mod_aiinteractivevideo'),
    new moodle_url('/mod/aiinteractivevideo/activation.php'),
    'moodle/site:config'
));

if ($ADMIN->fulltree) {
    // LMS Labs AI: the credentials stay on the server and are never sent to browsers.
    $source = \mod_aiinteractivevideo\local\credentials::source();
    $status = get_string('lmslabs_source_' . ($source ?: 'none'), 'mod_aiinteractivevideo');
    $settings->add(new admin_setting_heading(
        'mod_aiinteractivevideo/lmslabsheading',
        get_string('lmslabs', 'mod_aiinteractivevideo'),
        get_string('lmslabs_settings_desc', 'mod_aiinteractivevideo', \mod_aiinteractivevideo\local\lmslabs::COST) .
            html_writer::div(s($status), $source ? 'alert alert-info' : 'alert alert-warning') .
            html_writer::link(new moodle_url('/mod/aiinteractivevideo/activation.php'),
                get_string('act_settings_link', 'mod_aiinteractivevideo'))
    ));
    $settings->add(new admin_setting_configtext(
        'mod_aiinteractivevideo/lmslabs_siteid',
        get_string('lmslabs_siteid', 'mod_aiinteractivevideo'),
        get_string('lmslabs_siteid_desc', 'mod_aiinteractivevideo'),
        '',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'mod_aiinteractivevideo/lmslabs_apikey',
        get_string('lmslabs_apikey', 'mod_aiinteractivevideo'),
        get_string('lmslabs_apikey_desc', 'mod_aiinteractivevideo'),
        ''
    ));

    $counts = [];
    for ($i = 1; $i <= 20; $i++) {
        $counts[$i] = $i;
    }
    $settings->add(new admin_setting_configselect(
        'mod_aiinteractivevideo/defaultinteractions',
        get_string('numinteractions', 'mod_aiinteractivevideo'),
        get_string('numinteractions_help', 'mod_aiinteractivevideo'),
        5,
        $counts
    ));
    $settings->add(new admin_setting_configcheckbox(
        'mod_aiinteractivevideo/defaultsounds',
        get_string('sounds', 'mod_aiinteractivevideo'),
        get_string('sounds_desc', 'mod_aiinteractivevideo'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'mod_aiinteractivevideo/defaultleaderboard',
        get_string('leaderboard', 'mod_aiinteractivevideo'),
        get_string('leaderboard_desc', 'mod_aiinteractivevideo'),
        1
    ));
}
