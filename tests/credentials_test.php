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

namespace mod_aiinteractivevideo;

use mod_aiinteractivevideo\local\credentials;
use mod_aiinteractivevideo\local\lmslabs;
use mod_aiinteractivevideo\local\lmslabs_exception;

/**
 * LMS Labs credentials: Central Config (local_aiconfig) first, then a complete plugin pair, never mixed.
 *
 * @package    mod_aiinteractivevideo
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aiinteractivevideo\local\credentials
 */
final class credentials_test extends \advanced_testcase {
    /**
     * Central Config installed (test stand-in with the same API and settings).
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/aiinteractivevideo/tests/fixtures/central_config_stub.php');
        credentials::$centralclass = '\\mod_aiinteractivevideo\\tests\\fixtures\\central_config_stub';
    }

    /**
     * Back to the real Central Config class.
     */
    protected function tearDown(): void {
        credentials::$centralclass = '\\local_aiconfig\\config';
        parent::tearDown();
    }

    /**
     * Sets both pairs.
     *
     * @param string|null $centralsite
     * @param string|null $centralkey
     * @param string|null $localsite
     * @param string|null $localkey
     */
    private function set(?string $centralsite, ?string $centralkey, ?string $localsite, ?string $localkey): void {
        foreach (['siteid' => $centralsite, 'apikey' => $centralkey] as $name => $value) {
            $value === null ? unset_config($name, 'local_aiconfig') : set_config($name, $value, 'local_aiconfig');
        }
        foreach (['lmslabs_siteid' => $localsite, 'lmslabs_apikey' => $localkey] as $name => $value) {
            $value === null ? unset_config($name, 'mod_aiinteractivevideo') : set_config($name, $value, 'mod_aiinteractivevideo');
        }
    }

    /**
     * Only Central Config has the credentials: they are used (trimmed), nothing needs entering in this plugin.
     */
    public function test_central_only(): void {
        $this->set(' central-site ', ' central-key ', null, null);
        $this->assertSame(
            ['siteid' => 'central-site', 'apikey' => 'central-key', 'source' => 'central'],
            credentials::resolve()
        );
        $this->assertTrue(lmslabs::configured());
    }

    /**
     * Only this plugin has the credentials.
     */
    public function test_local_only(): void {
        $this->set(null, null, 'local-site', 'local-key');
        $this->assertSame(['siteid' => 'local-site', 'apikey' => 'local-key', 'source' => 'local'], credentials::resolve());
    }

    /**
     * Both complete: Central Config wins.
     */
    public function test_both_central_wins(): void {
        $this->set('central-site', 'central-key', 'local-site', 'local-key');
        $this->assertSame('central', credentials::source());
        $this->assertSame('central-key', credentials::resolve()['apikey']);
    }

    /**
     * Incomplete Central Config: the complete plugin pair is used, never a mix of the two.
     */
    public function test_incomplete_central_uses_complete_local_pair(): void {
        $this->set('central-site', '', 'local-site', 'local-key');
        $this->assertSame(['siteid' => 'local-site', 'apikey' => 'local-key', 'source' => 'local'], credentials::resolve());
    }

    /**
     * Half a pair in each place is not a pair: not configured, and nothing is sent.
     */
    public function test_halves_are_never_mixed(): void {
        $this->set('central-site', null, null, 'local-key');
        $this->assertFalse(credentials::configured());
        $this->set(null, 'central-key', 'local-site', null);
        $this->assertFalse(credentials::configured());
        $this->expectException(lmslabs_exception::class);
        lmslabs::build_request('prompt', [], 'key-1');
    }

    /**
     * Incomplete plugin pair and no Central Config values: not configured.
     */
    public function test_incomplete_local(): void {
        $this->set(null, null, 'local-site', '  ');
        $this->assertSame('', credentials::source());
        try {
            credentials::resolve();
            $this->fail('Expected not configured');
        } catch (lmslabs_exception $e) {
            $this->assertSame(lmslabs_exception::NOTCONFIGURED, $e->reason);
            $this->assertStringContainsString('Central Config', $e->getMessage());
        }
    }

    /**
     * Central Config not installed: the plugin pair still works on its own.
     */
    public function test_central_not_installed(): void {
        credentials::$centralclass = '\\local_aiconfig_not_installed\\config';
        $this->set('central-site', 'central-key', 'local-site', 'local-key');
        $this->assertFalse(credentials::central_installed());
        $this->assertSame('local', credentials::source());
        $this->set('central-site', 'central-key', null, null);
        $this->assertFalse(credentials::configured());
    }

    /**
     * Values are resolved on every use: a change in Central Config takes effect straight away and is never copied.
     */
    public function test_changes_take_effect_and_are_not_copied(): void {
        $this->set('site-a', 'key-a', null, null);
        $this->assertSame('site-a', credentials::resolve()['siteid']);
        $this->set('site-b', 'key-b', null, null);
        $this->assertSame('site-b', credentials::resolve()['siteid']);
        $this->assertFalse(get_config('mod_aiinteractivevideo', 'lmslabs_siteid'));
        $this->assertFalse(get_config('mod_aiinteractivevideo', 'lmslabs_apikey'));
    }
}
