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
 * An LMS Labs AI failure, with the reason kept separate so each case is handled correctly.
 *
 * UNREACHABLE (network failure, timeout or any 5xx answer), PROCESSING, CONFLICT and SITECHANGED leave the outcome
 * unknown or unfinished, so the idempotency key and the stored request are kept and the next try repeats exactly the
 * same request. Every other reason is a definite answer that nothing was charged.
 *
 * @package    mod_aiinteractivevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lmslabs_exception extends \moodle_exception {
    /** @var string The site has no LMS Labs Site ID or API key. */
    public const NOTCONFIGURED = 'lmslabs_notconfigured';
    /** @var string LMS Labs rejected the Site ID or API key (HTTP 401). */
    public const BADKEY = 'lmslabs_badkey';
    /** @var string LMS Labs rejected the request (HTTP 400). */
    public const BADREQUEST = 'lmslabs_badrequest';
    /** @var string Not enough LMS Labs AI credits. */
    public const INSUFFICIENT = 'lmslabs_insufficient';
    /** @var string LMS Labs could not be reached, timed out or had a server error. */
    public const UNREACHABLE = 'lmslabs_unreachable';
    /** @var string LMS Labs is still working on this request. */
    public const PROCESSING = 'lmslabs_processing';
    /** @var string LMS Labs AI could not generate the activities (no credits charged). */
    public const FAILED = 'lmslabs_failed';
    /** @var string LMS Labs does not offer this request yet (its answer was not an API response). Nothing was charged. */
    public const NOTAVAILABLE = 'lmslabs_notavailable';
    /** @var string LMS Labs holds a different request under this idempotency key (HTTP 409). */
    public const CONFLICT = 'lmslabs_conflict';
    /** @var string This site is not entitled to AI interactive video on LMS Labs (HTTP 403). */
    public const NOTENTITLED = 'lmslabs_notentitled';
    /** @var string The site's LMS Labs Site ID changed while a request was unfinished; nothing was sent. */
    public const SITECHANGED = 'lmslabs_sitechanged';
    /** @var string A new generation was refused because an earlier request has not finished. */
    public const UNRESOLVED = 'lmslabs_unresolved';
    /** @var string LMS Labs sent an answer the plugin cannot read. */
    public const BADRESPONSE = 'lmslabs_badresponse';

    /** @var string Reason, one of the constants above. */
    public $reason;
    /** @var int|null Remaining credits reported by LMS Labs. */
    public $credits;
    /** @var string LMS Labs link for buying credits. */
    public $buyurl;

    /**
     * Constructor.
     *
     * @param string $reason one of the constants
     * @param string $message safe provider message, if any
     * @param string $debug technical detail for developers
     * @param int|null $credits remaining credits
     * @param string $buyurl purchase link
     */
    public function __construct(
        string $reason, string $message = '', string $debug = '', ?int $credits = null,
        string $buyurl = ''
    ) {
        $this->reason = $reason;
        $this->credits = $credits;
        $this->buyurl = $buyurl;
        $a = (object)['cost' => lmslabs::COST, 'credits' => $credits ?? '?', 'message' => $message];
        parent::__construct($reason, 'mod_aiinteractivevideo', '', $a, $debug);
    }

    /**
     * Whether LMS Labs may have charged or may still be running this request (reuse the same key).
     *
     * @return bool
     */
    public function may_be_charged(): bool {
        return in_array($this->reason, [self::UNREACHABLE, self::PROCESSING, self::CONFLICT, self::SITECHANGED], true);
    }
}
