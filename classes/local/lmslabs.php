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
 * LMS Labs AI client: generates the interactions and charges LMS Labs AI credits on the LMS Labs server.
 *
 * One call does both: LMS Labs runs the AI and debits the credits only when the generation succeeds. Every generation
 * request carries an idempotency key, so repeating a request after a timeout returns the stored result and never
 * charges twice. The Site ID and API key come from LMS Labs Central Config or this plugin ({@see credentials}); they stay
 * in Moodle PHP and are never sent to the browser.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lmslabs {
    /** @var string LMS Labs service. A sandbox URL can be set in config.php as $CFG->mod_aiinteractivevideo_lmslabs_url. */
    public const BASEURL = 'https://lms-labs.com';

    /** @var string Plugin identifier sent to LMS Labs. */
    public const PLUGINID = 'mod_aiinteractivevideo';

    /** @var string Usage type sent to LMS Labs for one generation. */
    public const USAGETYPE = 'generate_activity';

    /** @var int LMS Labs AI credits for one generation (shown to teachers; the LMS Labs server enforces the tariff). */
    public const COST = 100;

    /** @var int Seconds allowed for a generation request. */
    private const GENERATE_TIMEOUT = 240;

    /** @var int Seconds allowed for a balance check. */
    private const BALANCE_TIMEOUT = 6;

    /**
     * Service base URL.
     *
     * @return string
     */
    public static function base_url(): string {
        global $CFG;
        $url = !empty($CFG->mod_aiinteractivevideo_lmslabs_url) ? (string)$CFG->mod_aiinteractivevideo_lmslabs_url : self::BASEURL;
        return rtrim($url, '/');
    }

    /**
     * Whether the site has LMS Labs credentials.
     *
     * @return bool
     */
    public static function configured(): bool {
        return credentials::configured();
    }

    /**
     * Advisory credit balance for display only (it never authorises a charge).
     *
     * @return array|null ['unlimited' => bool, 'credits' => int|null] or null when unknown
     */
    public static function balance(): ?array {
        try {
            $pair = credentials::resolve();
        } catch (lmslabs_exception $e) {
            return null;
        }
        $curl = self::curl(self::BALANCE_TIMEOUT);
        $curl->setHeader(['X-API-Key: ' . $pair['apikey'], 'Accept: application/json']);
        $body = $curl->get(self::base_url() . '/api/credits', ['siteId' => $pair['siteid']]);
        $info = $curl->get_info();
        if ($curl->get_errno() || (int)($info['http_code'] ?? 0) !== 200) {
            return null;
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            return null;
        }
        if (!empty($data['isUnlimited'])) {
            return ['unlimited' => true, 'credits' => null];
        }
        $credits = $data['credits'] ?? null;
        return ['unlimited' => false, 'credits' => is_numeric($credits) ? (int)$credits : null];
    }

    /** @var array Test only: queued [status, body] answers used instead of the network (PHPUnit only). */
    public static $testanswers = [];

    /** @var array Test only: requests sent, each ['idempotencykey' => header value, 'body' => exact JSON] (PHPUnit only). */
    public static $testsent = [];

    /**
     * The exact generation request body for one idempotency key. It is stored before the first call and sent
     * byte for byte on every retry of the same request.
     *
     * @param string $prompt the complete generation prompt (timed transcript and rules)
     * @param array $metadata non-personal details about the request (activity ids, counts, versions)
     * @param string $idempotencykey a new UUID for a new generation request
     * @return string JSON body
     * @throws lmslabs_exception when LMS Labs is not set up
     */
    public static function build_request(string $prompt, array $metadata, string $idempotencykey): string {
        $pair = credentials::resolve();
        return json_encode([
            'siteId' => $pair['siteid'],
            'pluginId' => self::PLUGINID,
            'usageType' => self::USAGETYPE,
            'idempotencyKey' => $idempotencykey,
            'prompt' => $prompt,
            'metadata' => (object)$metadata,
        ]);
    }

    /**
     * Sends a stored generation request. LMS Labs runs the AI and charges the credits in the same request, once
     * per idempotency key: repeating the same key and body returns the stored result without another debit.
     *
     * Success is only "ok": true with output, whatever the HTTP status. Network failures, 202, "processing"
     * answers, 5xx answers (with or without an error field) and 409 are reported with reasons that keep the key
     * and body, so the next try repeats exactly the same request.
     *
     * @param string $body the stored JSON body from {@see build_request()}
     * @return array ['output' => model text, 'credits' => remaining credits or null, 'replayed' => bool]
     * @throws lmslabs_exception
     */
    public static function send(string $body): array {
        $request = json_decode($body, true);
        $key = is_array($request) ? (string)($request['idempotencyKey'] ?? '') : '';
        if ($key === '' || ($request['pluginId'] ?? '') !== self::PLUGINID) {
            throw new lmslabs_exception(lmslabs_exception::BADREQUEST);
        }
        // One complete pair for the whole request (never a central Site ID with a local key, or the reverse).
        // The stored request names the site it was made for. If the site's credentials changed, nothing is sent.
        $pair = credentials::resolve();
        if ((string)$request['siteId'] !== (string)$pair['siteid']) {
            throw new lmslabs_exception(lmslabs_exception::SITECHANGED);
        }
        [$errno, $status, $answer, $error] = self::post('/api/generate-interactivevideo', [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $pair['apikey'],
            'Idempotency-Key: ' . $key,
        ], $body, $key);
        if ($errno) {
            // Network failure or timeout: LMS Labs may still finish and charge, so the same key must be reused.
            throw new lmslabs_exception(lmslabs_exception::UNREACHABLE, '', $error);
        }
        $data = json_decode((string)$answer, true);
        $state = is_array($data) ? (string)($data['status'] ?? '') : '';
        if ($status === 202 || $state === 'processing' || $state === 'pending') {
            throw new lmslabs_exception(lmslabs_exception::PROCESSING);
        }
        if ($status >= 500) {
            // Server error, even with an error field: the outcome is unknown, so the key and body are kept.
            throw new lmslabs_exception(lmslabs_exception::UNREACHABLE, '', 'HTTP ' . $status);
        }
        if ($status === 409) {
            throw new lmslabs_exception(lmslabs_exception::CONFLICT, '', 'HTTP 409');
        }
        if ($status === 401) {
            throw new lmslabs_exception(lmslabs_exception::BADKEY);
        }
        if ($status === 403) {
            throw new lmslabs_exception(lmslabs_exception::NOTENTITLED, self::safe_message($data));
        }
        if ($status === 400) {
            throw new lmslabs_exception(lmslabs_exception::BADREQUEST, self::safe_message($data));
        }
        if (!is_array($data)) {
            // Not an API answer (for example the LMS Labs website page, or 404/405): the service does not offer this.
            // Nothing was generated or charged.
            throw new lmslabs_exception(lmslabs_exception::NOTAVAILABLE, '', 'HTTP ' . $status . ', not JSON');
        }
        if (($data['ok'] ?? false) !== true) {
            $code = (string)($data['error'] ?? '');
            if ($code === 'INSUFFICIENT_CREDITS') {
                $credits = isset($data['credits']) && is_numeric($data['credits']) ? (int)$data['credits'] : null;
                $buyurl = self::safe_url($data['buyUrl'] ?? '');
                throw new lmslabs_exception(lmslabs_exception::INSUFFICIENT, '', '', $credits, $buyurl);
            }
            throw new lmslabs_exception(lmslabs_exception::FAILED, self::safe_message($data));
        }
        $output = $data['output'] ?? null;
        if (!is_string($output) || trim($output) === '') {
            throw new lmslabs_exception(lmslabs_exception::BADRESPONSE);
        }
        $credits = $data['credits'] ?? null;
        return [
            'output' => $output,
            'credits' => is_numeric($credits) ? (int)$credits : null,
            'replayed' => !empty($data['replayed']),
        ];
    }

    /**
     * Server-to-server POST with Moodle's HTTP client.
     *
     * @param string $path
     * @param array $headers
     * @param string $body
     * @param string $key idempotency key (recorded by tests)
     * @return array [curl errno, HTTP status, response body, error text]
     */
    private static function post(string $path, array $headers, string $body, string $key): array {
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            self::$testsent[] = ['idempotencykey' => $key, 'body' => $body];
            if (self::$testanswers) {
                [$status, $answer] = array_shift(self::$testanswers);
                return $status === 0 ? [28, 0, '', 'Operation timed out'] : [0, $status, $answer, ''];
            }
        }
        $curl = self::curl(self::GENERATE_TIMEOUT);
        $curl->setHeader($headers);
        $answer = $curl->post(self::base_url() . $path, $body);
        return [(int)$curl->get_errno(), (int)($curl->get_info()['http_code'] ?? 0), (string)$answer, (string)$curl->error];
    }

    /**
     * A new idempotency key for one generation request.
     *
     * @return string
     */
    public static function new_key(): string {
        return \core\uuid::generate();
    }

    /**
     * Moodle curl with bounded timeouts (site proxy, TLS and security settings apply).
     *
     * @param int $timeout
     * @return \curl
     */
    private static function curl(int $timeout): \curl {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setopt([
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_FOLLOWLOCATION' => false,
        ]);
        return $curl;
    }

    /**
     * Provider error text, shortened and stripped of markup.
     *
     * @param mixed $data
     * @return string
     */
    private static function safe_message($data): string {
        if (!is_array($data)) {
            return '';
        }
        $message = (string)($data['message'] ?? $data['error'] ?? '');
        return \core_text::substr(trim(strip_tags($message)), 0, 300);
    }

    /**
     * A purchase link from LMS Labs, only when it is an https URL on the LMS Labs host.
     *
     * @param mixed $url
     * @return string
     */
    private static function safe_url($url): string {
        $url = clean_param((string)$url, PARAM_URL);
        if ($url === '' || !preg_match('~^https://~i', $url)) {
            return '';
        }
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        return ($host === 'lms-labs.com' || str_ends_with($host, '.lms-labs.com')) ? $url : '';
    }
}
