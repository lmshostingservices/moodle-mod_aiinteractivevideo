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
 * Server-side, administrator-confirmed site activation. This state is separate from generation and credentials.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class unlock {
    /** @var callable|null Injected transport for isolated tests: fn(request): [httpstatus, rawbody]. */
    public static $transport = null;

    /** @var string Component and API identities. */
    const COMPONENT = 'mod_aiinteractivevideo';
    const SHORTID = 'aiinteractivevideo';
    const BASE = 'https://lms-labs.com';

    /**
     * Request without redirects or logging payloads. A malformed response is never interpreted as a purchase.
     *
     * @param string $method HTTP method.
     * @param string $path API path.
     * @param array|null $body JSON body.
     * @return array [HTTP status, decoded response or null].
     */
    private static function request(string $method, string $path, ?array $body = null): array {
        $payload = $body === null ? null : json_encode($body);
        $base = $GLOBALS['CFG']->forced_plugin_settings[self::COMPONENT]['lmslabs_unlock_base'] ?? self::BASE;
        $url = rtrim((string)$base, '/') . $path;
        if (self::$transport) {
            [$status, $raw] = (self::$transport)(['method' => $method, 'url' => $url, 'body' => $payload]);
        } else {
            try {
                $client = new \core\http_client();
                $options = ['headers' => ['Accept' => 'application/json'], 'timeout' => 30, 'connect_timeout' => 10,
                    'allow_redirects' => false, 'http_errors' => false];
                if ($payload !== null) {
                    $options['headers']['Content-Type'] = 'application/json';
                    $options['body'] = $payload;
                }
                $response = $client->request($method, $url, $options);
                $status = $response->getStatusCode();
                $raw = (string)$response->getBody();
            } catch (\Throwable $e) {
                return [0, null];
            }
        }
        $data = json_decode((string)$raw, true);
        return [(int)$status, is_array($data) ? $data : null];
    }

    /**
     * Read an unsigned whole credit balance, or the unlimited sentinel.
     *
     * @param mixed $value Server field.
     * @return string Printable balance.
     */
    public static function balance($value): string {
        if ($value === -1 || $value === '-1' || $value === 'unlimited') {
            return 'Unlimited';
        }
        return self::whole($value, true) !== null ? (string)$value : 'Unknown';
    }

    /**
     * @param mixed $value Numeric field.
     * @param bool $allowzero Allow zero.
     * @return int|null Valid integer.
     */
    private static function whole($value, bool $allowzero = false): ?int {
        if (!(is_int($value) || (is_string($value) && ctype_digit($value)))) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $allowzero ? 0 : 1]]);
        return $number === false ? null : $number;
    }

    /**
     * Stored state, never the credential pair.
     *
     * @return array Access state.
     */
    public static function state(): array {
        $state = json_decode((string)get_config(self::COMPONENT, 'unlockstate'), true);
        return is_array($state) && isset($state['status']) ? $state : ['status' => 'notchecked'];
    }

    /**
     * @return array|null Unresolved purchase marker.
     */
    public static function pending(): ?array {
        $value = json_decode((string)get_config(self::COMPONENT, 'unlockpending'), true);
        return is_array($value) && !empty($value['time']) ? $value : null;
    }

    /**
     * Free check. An incomplete/malformed answer does not resolve an uncertain purchase.
     *
     * @return array Access state.
     */
    public static function verify(): array {
        $factory = \core\lock\lock_config::get_lock_factory(self::COMPONENT);
        $lock = $factory->get_lock('site_unlock', 10);
        if (!$lock) {
            return ['status' => 'unknown', 'error' => 'Activation in progress; check again later'];
        }
        try {
            return self::verify_locked();
        } finally {
            $lock->release();
        }
    }

    /**
     * Free verification while holding the site activation lock (also used before a purchase).
     *
     * @return array Access state.
     */
    private static function verify_locked(): array {
        try {
            $creds = credentials::resolve();
        } catch (\Throwable $e) {
            $state = ['status' => 'unknown', 'error' => 'Credentials are not configured'];
            set_config('unlockstate', json_encode($state), self::COMPONENT);
            return $state;
        }
        [$status, $data] = self::request('POST', '/api/plugin-unlock/verify', [
            'pluginId' => self::SHORTID, 'siteId' => $creds['siteid'], 'apiKey' => $creds['apikey'],
        ]);
        if ($status === 200 && is_bool($data['unlocked'] ?? null)) {
            $state = ['status' => $data['unlocked'] ? 'unlocked' : 'locked',
                'balance' => self::balance($data['credits'] ?? null),
                'source' => self::safe($data['entitlementSource'] ?? '', 60), 'checkedat' => time()];
            if (self::pending()) {
                unset_config('unlockpending', self::COMPONENT);
                $state['resolved'] = true;
            }
        } else {
            $state = ['status' => 'unknown', 'error' => 'Access check unavailable (HTTP ' . $status . ')',
                'checkedat' => time()];
        }
        set_config('unlockstate', json_encode($state), self::COMPONENT);
        return $state;
    }

    /**
     * Live release price and SHA, never a locally hardcoded tariff.
     *
     * @return array Release entry with ok or reason.
     */
    public static function release(): array {
        [$status, $data] = self::request('GET', '/api/plugin-versions');
        if ($status !== 200 || !is_array($data)) {
            return ['ok' => false, 'reason' => 'Release catalogue unavailable'];
        }
        $entry = $data['plugins'][self::COMPONENT] ?? null;
        if (!is_array($entry) || ($entry['component'] ?? null) !== self::COMPONENT) {
            return ['ok' => false, 'reason' => 'Release not listed'];
        }
        $price = self::whole($entry['creditsRequired'] ?? null);
        $sha = $entry['sha256'] ?? '';
        if (($entry['status'] ?? null) !== 'ready' || ($entry['zipExists'] ?? null) !== true ||
                ($entry['acquisitionMode'] ?? null) !== 'credit-unlock' || $price !== 50 ||
                !is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D', $sha)) {
            return ['ok' => false, 'reason' => 'Release, ZIP, price or SHA not confirmed'];
        }
        return ['ok' => true, 'price' => $price, 'sha' => $sha,
            'version' => self::safe($entry['version'] ?? '', 30)];
    }

    /**
     * Fetch fresh access and price before showing a confirmation. A pending request blocks purchase, not checks.
     *
     * @return array [state, release, blocked].
     */
    public static function review(): array {
        // Do not let a review automatically resolve pending state: recovery requires an explicit Check access.
        if (self::pending()) {
            return ['state' => self::state(), 'release' => self::release(), 'blocked' => 'Check access to resolve the pending request'];
        }
        $state = self::verify();
        $release = self::release();
        $blocked = $state['status'] === 'unlocked' ? 'Already unlocked'
            : ($state['status'] !== 'locked' ? 'Access not verified'
            : (!$release['ok'] ? $release['reason'] : ''));
        return compact('state', 'release', 'blocked');
    }

    /**
     * Buy only after confirmation of both price and SHA. Write pending before network dispatch.
     *
     * @param int $expected Confirmed price.
     * @param string $sha Confirmed SHA.
     * @return array Outcome and safe display fields.
     */
    public static function buy(int $expected, string $sha): array {
        // Serialize concurrent admin confirmations so two requests cannot both pass the pending check.
        $factory = \core\lock\lock_config::get_lock_factory(self::COMPONENT);
        $lock = $factory->get_lock('site_unlock', 10);
        if (!$lock) {
            return ['outcome' => 'Another activation is in progress'];
        }
        try {
            return self::buy_locked($expected, $sha);
        } finally {
            $lock->release();
        }
    }

    /**
     * Purchase under the site-wide activation lock.
     *
     * @param int $expected Confirmed credit price.
     * @param string $sha Confirmed release SHA.
     * @return array Outcome and safe display fields.
     */
    private static function buy_locked(int $expected, string $sha): array {
        if (self::pending()) {
            return ['outcome' => 'pending'];
        }
        try {
            $creds = credentials::resolve();
        } catch (\Throwable $e) {
            return ['outcome' => 'Credentials are not configured'];
        }
        $state = self::verify_locked();
        if ($state['status'] === 'unlocked') {
            return ['outcome' => 'already'];
        }
        if ($state['status'] !== 'locked') {
            return ['outcome' => 'Access not verified'];
        }
        $release = self::release();
        if (!$release['ok'] || $release['price'] !== $expected || !hash_equals($release['sha'], $sha)) {
            return ['outcome' => 'Release or price changed. Review and confirm again'];
        }
        // The Moodle config write must complete before dispatch. Failure must never send a purchase.
        if (!set_config('unlockpending', json_encode(['time' => time(), 'price' => $expected, 'sha' => $sha]),
                self::COMPONENT)) {
            return ['outcome' => 'Unable to save pending request'];
        }
        [$status, $data] = self::request('POST', '/api/plugin-unlock', [
            'pluginId' => self::SHORTID, 'pluginComponent' => self::COMPONENT,
            'releaseSha256' => $sha, 'expectedCredits' => $expected,
            'siteId' => $creds['siteid'], 'apiKey' => $creds['apikey'],
        ]);
        if ($status >= 200 && $status < 300 && is_bool($data['success'] ?? null) && $data['success'] === true &&
                (!array_key_exists('alreadyUnlocked', $data) || is_bool($data['alreadyUnlocked']))) {
            unset_config('unlockpending', self::COMPONENT);
            $balance = self::balance($data['remainingCredits'] ?? null);
            $source = self::safe($data['entitlementSource'] ?? '', 60);
            $consumed = self::whole($data['creditsConsumed'] ?? null, true);
            $outcome = ($data['alreadyUnlocked'] ?? false) ? 'already' :
                ($consumed === 0 && in_array(strtolower($source), ['marketplace', 'purchase'], true)
                ? 'restored' : 'unlocked');
            set_config('unlockstate', json_encode(['status' => 'unlocked', 'balance' => $balance,
                'source' => $source, 'checkedat' => time()]), self::COMPONENT);
            return compact('outcome', 'balance', 'source', 'consumed');
        }
        // A known refusal is definitive; network errors, malformed 2xx, timeouts and 5xx are NOT.
        $code = strtolower(self::safe($data['code'] ?? $data['error'] ?? '', 60));
        if ($status === 402 || ($status >= 400 && $status < 500 && $status !== 408 && $status !== 429) ||
                ($status >= 200 && $status < 300 && ($data['success'] ?? null) === false && $code !== '')) {
            unset_config('unlockpending', self::COMPONENT);
            return ['outcome' => 'refused', 'reason' => $status === 402 ? 'Insufficient credits'
                : 'HTTP ' . $status . ' ' . $code,
                'balance' => self::balance($data['currentCredits'] ?? null)];
        }
        return ['outcome' => 'uncertain', 'reason' => 'Response not confirmed (HTTP ' . $status . ')'];
    }

    /**
     * @param mixed $value Remote text.
     * @param int $length Maximum characters.
     * @return string Escapable plain text.
     */
    private static function safe($value, int $length): string {
        return is_string($value) ? \core_text::substr(clean_param($value, PARAM_TEXT), 0, $length) : '';
    }
}