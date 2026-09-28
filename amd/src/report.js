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
 * Report page: select-all and delete confirmation.
 *
 * @module     mod_aiinteractivevideo/report
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {getStrings} from 'core/str';

/**
 * Entry point.
 *
 * @param {string} selector
 */
export const init = async(selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    const form = root.querySelector('[data-region="attemptsform"]');
    if (!form) {
        return;
    }
    const [title, question, yes] = await getStrings([
        {key: 'confirm', component: 'core'},
        {key: 'deleteattemptsconfirm', component: 'mod_aiinteractivevideo'},
        {key: 'yes', component: 'core'},
    ]);
    const boxes = () => Array.from(form.querySelectorAll('[data-region="attemptcheck"]'));
    const del = root.querySelector('[data-action="deleteattempts"]');
    const sync = () => {
        if (del) {
            del.disabled = !boxes().some((b) => b.checked);
        }
    };
    form.addEventListener('change', (e) => {
        if (e.target.matches('[data-action="selectall"]')) {
            boxes().forEach((b) => {
                b.checked = e.target.checked;
            });
        }
        sync();
    });
    if (del) {
        del.addEventListener('click', (e) => {
            e.preventDefault();
            Notification.confirm(title, question, yes, null, () => form.submit());
        });
    }
};
