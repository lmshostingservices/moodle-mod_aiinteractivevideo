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
 * Interaction builder page: AI build with progress, confirmations, prompt copy and the timeline strip.
 *
 * @module     mod_aiinteractivevideo/builder
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {getStrings} from 'core/str';

/**
 * Entry point.
 *
 * @param {string} selector
 * @param {number} cmid
 */
export const init = async(selector, cmid) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    const [confirmTitle, regenerateConfirm, deleteConfirm, yes] = await getStrings([
        {key: 'confirm', component: 'core'},
        {key: 'lmslabs_regenerate_confirm', component: 'mod_aiinteractivevideo'},
        {key: 'deletesectionconfirm', component: 'mod_aiinteractivevideo'},
        {key: 'yes', component: 'core'},
    ]);

    // Proportional timeline strip.
    root.querySelectorAll('.aiv-ed-seg').forEach((seg) => {
        seg.style.left = `${seg.dataset.left}%`;
        seg.style.width = `${seg.dataset.width}%`;
    });

    // A generation was requested when the settings were saved: finish it here.
    const build = root.querySelector('[data-region="build"]');
    if (build && build.dataset.pending) {
        runGeneration(root, cmid, 'continue');
    }

    root.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) {
            return;
        }
        const action = btn.dataset.action;
        if (action === 'deletesection') {
            e.preventDefault();
            Notification.confirm(confirmTitle, deleteConfirm, yes, null, () => {
                window.location.href = btn.href;
            });
        } else if (action === 'regenerate') {
            // A new generation is charged: always confirm first.
            Notification.confirm(confirmTitle, regenerateConfirm, yes, null, () => runGeneration(root, cmid, 'regenerate'));
        } else if (action === 'retry') {
            runGeneration(root, cmid, 'retry');
        }
    });
};

/**
 * Runs the LMS Labs AI generation with an animated progress list, then reloads the page.
 *
 * @param {HTMLElement} root
 * @param {number} cmid
 * @param {string} action continue, retry or regenerate
 */
const runGeneration = async(root, cmid, action) => {
    const progress = root.querySelector('[data-region="progress"]');
    const steps = progress.querySelectorAll('li');
    root.querySelectorAll('[data-region="build"] [data-action]').forEach((b) => {
        b.disabled = true;
    });
    progress.hidden = false;
    let i = 0;
    const tick = () => {
        steps.forEach((li, k) => {
            li.classList.toggle('is-done', k < i);
            li.classList.toggle('is-active', k === i);
        });
        i = Math.min(i + 1, steps.length - 1);
    };
    tick();
    const handle = window.setInterval(tick, 4000);
    try {
        await Ajax.call([{methodname: 'mod_aiinteractivevideo_generate', args: {cmid, action}}])[0];
        steps.forEach((li) => li.classList.add('is-done'));
    } catch (err) {
        // The reason is stored with the activity and shown after the reload.
        window.console.warn(err);
    } finally {
        window.clearInterval(handle);
        window.setTimeout(() => window.location.reload(), 600);
    }
};
