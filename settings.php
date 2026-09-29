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

if ($ADMIN->fulltree) {
    // LMS Labs AI: the credentials stay on the server and are never sent to browsers.
    $source = \mod_aiinteractivevideo\local\credentials::source();
    $status = get_string('lmslabs_source_' . ($source ?: 'none'), 'mod_aiinteractivevideo');
    $state = \mod_aiinteractivevideo\local\unlock::state();
    $actionurl = new moodle_url('/mod/aiinteractivevideo/activation.php');
    $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'modsettingaiinteractivevideo']);
    $access = html_writer::tag('p', s(get_string('act_status', 'mod_aiinteractivevideo', $state['status'])));
    $access .= html_writer::tag('button', get_string('act_check', 'mod_aiinteractivevideo'), [
        'type' => 'submit', 'name' => 'action', 'value' => 'check',
        'formaction' => $actionurl->out(false), 'formmethod' => 'post', 'class' => 'btn btn-secondary btn-sm',
    ]);
    $access .= ' ' . html_writer::link(new moodle_url($settingsurl, ['unlockreview' => 1]),
        get_string('act_review', 'mod_aiinteractivevideo'), ['class' => 'btn btn-secondary btn-sm']);
    if (optional_param('unlockreview', 0, PARAM_BOOL)) {
        $review = \mod_aiinteractivevideo\local\unlock::review();
        if ($review['blocked'] === '' && ($review['release']['price'] ?? null) === 50) {
            $release = $review['release'];
            $access .= html_writer::tag('p', s(get_string('act_confirm', 'mod_aiinteractivevideo', (object)[
                'price' => 50, 'balance' => $review['state']['balance'] ?? 'Unknown',
                'version' => $release['version'], 'sha' => $release['sha'],
            ])));
            foreach (['confirm' => 1, 'expected' => 50, 'sha' => $release['sha']] as $name => $value) {
                $access .= html_writer::empty_tag('input', [
                    'type' => 'hidden', 'name' => $name, 'value' => $value,
                ]);
            }
            $access .= html_writer::tag('button', get_string('act_buy', 'mod_aiinteractivevideo', 50), [
                'type' => 'submit', 'name' => 'action', 'value' => 'unlock',
                'formaction' => $actionurl->out(false), 'formmethod' => 'post', 'class' => 'btn btn-primary',
            ]);
        } else {
            $access .= html_writer::div(s($review['blocked'] ?: 'The 50-credit price is unavailable.'),
                'alert alert-warning');
        }
    }
    $settings->add(new admin_setting_heading(
        'mod_aiinteractivevideo/lmslabsheading',
        get_string('lmslabs', 'mod_aiinteractivevideo'),
        get_string('lmslabs_settings_desc', 'mod_aiinteractivevideo', \mod_aiinteractivevideo\local\lmslabs::COST) .
            html_writer::div(s($status) . ' ' .
                (\mod_aiinteractivevideo\local\credentials::central_installed()
                    ? html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'local_aiconfig']),
                        get_string('act_central', 'mod_aiinteractivevideo')) : ''),
                $source ? 'alert alert-info' : 'alert alert-warning') .
            $access
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
