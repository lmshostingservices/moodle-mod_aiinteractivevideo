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

namespace mod_aiinteractivevideo\tests\fixtures;

/**
 * Test stand-in for \local_aiconfig\config (LMS Labs Central Config), with the same public API and settings.
 *
 * @package    mod_aiinteractivevideo
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class central_config_stub {
    /**
     * Credentials as local_aiconfig returns them.
     *
     * @return array ['siteid' => string, 'apikey' => string]
     */
    public static function get_credentials(): array {
        return [
            'siteid' => trim((string)get_config('local_aiconfig', 'siteid')),
            'apikey' => trim((string)get_config('local_aiconfig', 'apikey')),
        ];
    }
}
