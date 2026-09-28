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

namespace mod_aiinteractivevideo\local;

/**
 * Resolves the site's LMS Labs credentials: LMS Labs Central Config (local_aiconfig) first, then this plugin's own pair.
 *
 * Only a complete pair is used; a central Site ID is never mixed with a local API key or the other way round. Values are
 * read when needed and never copied, so changes in Central Config take effect straight away. The API key stays on the
 * server.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class credentials {
    /** @var string Where the credentials come from: Central Config. */
    public const CENTRAL = 'central';

    /** @var string Where the credentials come from: this plugin's settings. */
    public const LOCAL = 'local';

    /** @var string Central Config helper class. Tests may point this at a class that does not exist. */
    public static $centralclass = '\\local_aiconfig\\config';

    /**
     * A complete credential pair, Central Config first.
     *
     * @return array ['siteid' => string, 'apikey' => string, 'source' => central|local]
     * @throws lmslabs_exception when neither Central Config nor this plugin has both values
     */
    public static function resolve(): array {
        $central = self::central();
        if ($central) {
            return $central;
        }
        $siteid = trim((string)get_config('mod_aiinteractivevideo', 'lmslabs_siteid'));
        $apikey = trim((string)get_config('mod_aiinteractivevideo', 'lmslabs_apikey'));
        if ($siteid !== '' && $apikey !== '') {
            return ['siteid' => $siteid, 'apikey' => $apikey, 'source' => self::LOCAL];
        }
        throw new lmslabs_exception(lmslabs_exception::NOTCONFIGURED);
    }

    /**
     * Whether a complete pair is available (not proof that LMS Labs will accept it).
     *
     * @return bool
     */
    public static function configured(): bool {
        try {
            self::resolve();
            return true;
        } catch (lmslabs_exception $e) {
            return false;
        }
    }

    /**
     * Where the credentials in use come from, for the settings page.
     *
     * @return string central, local or '' when not configured
     */
    public static function source(): string {
        try {
            return self::resolve()['source'];
        } catch (lmslabs_exception $e) {
            return '';
        }
    }

    /**
     * Whether LMS Labs Central Config is installed.
     *
     * @return bool
     */
    public static function central_installed(): bool {
        return class_exists(self::$centralclass);
    }

    /**
     * The complete pair from Central Config, if any.
     *
     * @return array|null
     */
    private static function central(): ?array {
        if (!self::central_installed()) {
            return null;
        }
        $class = self::$centralclass;
        try {
            $pair = $class::get_credentials();
        } catch (\Throwable $e) {
            debugging('LMS Labs Central Config could not be read: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
        $siteid = trim((string)($pair['siteid'] ?? ''));
        $apikey = trim((string)($pair['apikey'] ?? ''));
        if ($siteid === '' || $apikey === '') {
            return null;
        }
        return ['siteid' => $siteid, 'apikey' => $apikey, 'source' => self::CENTRAL];
    }
}
