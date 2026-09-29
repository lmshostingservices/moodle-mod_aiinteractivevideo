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
 * Administrator-only, explicit one-time site activation. No purchase on GET or review.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_aiinteractivevideo\local\credentials;
use mod_aiinteractivevideo\local\unlock;

require_login();
require_capability('moodle/site:config', context_system::instance());
$url = new moodle_url('/admin/settings.php', ['section' => 'modsettingaiinteractivevideo']);
$PAGE->set_url($url);
$action = optional_param('action', '', PARAM_ALPHA);
// This endpoint only accepts explicit settings actions. A GET never checks or buys access.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !in_array($action, ['check', 'unlock'], true)) {
    redirect($url);
}
require_sesskey();

if ($action === 'check') {
    $state = unlock::verify();
    redirect($url, get_string('act_checked', 'mod_aiinteractivevideo', $state['status']), null,
        $state['status'] === 'unknown' ? \core\output\notification::NOTIFY_WARNING
            : \core\output\notification::NOTIFY_SUCCESS);
}
if ($action === 'unlock') {
    if (required_param('confirm', PARAM_BOOL) !== 1) {
        throw new moodle_exception('invalidrequest');
    }
    $result = unlock::buy(required_param('expected', PARAM_INT), required_param('sha', PARAM_ALPHANUM));
    $outcome = $result['outcome'];
    if (in_array($outcome, ['unlocked', 'restored', 'already'], true)) {
        $message = get_string('act_result_' . $outcome, 'mod_aiinteractivevideo');
        if ($outcome === 'unlocked' && $result['consumed'] !== null) {
            $message .= ' ' . get_string('act_consumed', 'mod_aiinteractivevideo', $result['consumed']);
        }
        if (isset($result['balance'])) {
            $message .= ' ' . get_string('act_balance', 'mod_aiinteractivevideo', $result['balance']);
        }
        $type = \core\output\notification::NOTIFY_SUCCESS;
    } else {
        $message = get_string('act_result_failed', 'mod_aiinteractivevideo',
            $result['reason'] ?? $outcome);
        if (isset($result['balance'])) {
            $message .= ' ' . get_string('act_balance', 'mod_aiinteractivevideo', $result['balance']);
        }
        $type = \core\output\notification::NOTIFY_WARNING;
    }
    redirect($url, $message, null, $type);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('act_title', 'mod_aiinteractivevideo'));
echo html_writer::tag('p', get_string('act_intro', 'mod_aiinteractivevideo'));
$source = credentials::source();
echo html_writer::tag('p', get_string('act_credentials', 'mod_aiinteractivevideo', $source ?: 'not configured'));
echo html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'modsettingaiinteractivevideo']),
    get_string('act_settings', 'mod_aiinteractivevideo'));
if (credentials::central_installed()) {
    echo ' · ' . html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'local_aiconfig']),
        get_string('act_central', 'mod_aiinteractivevideo'));
}

if ($action === 'review') {
    $review = unlock::review();
    if ($review['blocked'] !== '') {
        echo $OUTPUT->notification(s($review['blocked']), \core\output\notification::NOTIFY_WARNING);
    } else {
        $release = $review['release'];
        $balance = $review['state']['balance'] ?? 'Unknown';
        echo html_writer::tag('p', get_string('act_confirm', 'mod_aiinteractivevideo', (object)[
            'price' => $release['price'], 'balance' => $balance,
            'version' => $release['version'], 'sha' => $release['sha'],
        ]));
        if ($balance !== 'Unknown' && $balance !== 'Unlimited' && (int)$balance < $release['price']) {
            echo $OUTPUT->notification(get_string('act_low', 'mod_aiinteractivevideo'),
                \core\output\notification::NOTIFY_WARNING);
        }
        // All confirmation fields are POST only, and both values are revalidated against the live catalogue.
        echo html_writer::start_tag('form', ['action' => $url->out(false), 'method' => 'post']);
        foreach (['sesskey' => sesskey(), 'action' => 'unlock', 'confirm' => 1,
            'expected' => $release['price'], 'sha' => $release['sha']] as $name => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        echo html_writer::tag('button', get_string('act_buy', 'mod_aiinteractivevideo', $release['price']),
            ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
    }
    echo $OUTPUT->continue_button($url);
    echo $OUTPUT->footer();
    exit;
}

$state = unlock::state();
$release = unlock::release();
$pending = unlock::pending();
echo html_writer::tag('p', get_string('act_status', 'mod_aiinteractivevideo', $state['status']));
if (!empty($state['error'])) {
    echo $OUTPUT->notification(s($state['error']), \core\output\notification::NOTIFY_WARNING);
}
if (!empty($state['source'])) {
    echo html_writer::tag('p', get_string('act_source', 'mod_aiinteractivevideo', s($state['source'])));
}
echo html_writer::tag('p', get_string('act_balance', 'mod_aiinteractivevideo', $state['balance'] ?? 'Unknown'));
echo html_writer::tag('p', $release['ok']
    ? get_string('act_price', 'mod_aiinteractivevideo', (object)['price' => $release['price'],
        'version' => $release['version'], 'sha' => $release['sha']])
    : get_string('act_unavailable', 'mod_aiinteractivevideo', s($release['reason'])));
if ($pending) {
    echo $OUTPUT->notification(get_string('act_pending', 'mod_aiinteractivevideo'),
        \core\output\notification::NOTIFY_WARNING);
}
$check = new single_button(new moodle_url($url, ['action' => 'check']),
    get_string('act_check', 'mod_aiinteractivevideo'), 'post');
$check->disabled = $source === '';
$buy = new single_button(new moodle_url($url, ['action' => 'review']),
    get_string('act_review', 'mod_aiinteractivevideo'), 'post', single_button::BUTTON_PRIMARY);
$buy->disabled = $source === '' || $pending !== null || $state['status'] === 'unlocked' || !$release['ok'];
echo $OUTPUT->render($check) . $OUTPUT->render($buy);
echo $OUTPUT->footer();