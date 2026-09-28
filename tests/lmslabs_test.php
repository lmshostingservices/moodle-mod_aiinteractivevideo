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

use mod_aiinteractivevideo\local\lmslabs;
use mod_aiinteractivevideo\local\lmslabs_exception;
use mod_aiinteractivevideo\local\manager;

/**
 * LMS Labs AI generation: success, credits, failures and the no-double-charge rules.
 *
 * @package    mod_aiinteractivevideo
 * @category   test
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aiinteractivevideo\local\lmslabs
 * @covers     \mod_aiinteractivevideo\local\lmslabs_exception
 * @covers     \mod_aiinteractivevideo\local\manager
 */
final class lmslabs_test extends \advanced_testcase {
    /** @var \stdClass */
    private $instance;
    /** @var \context_module */
    private $context;

    /**
     * An activity that has not been created yet, on a site with LMS Labs credentials.
     */
    private function setup_activity(): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $this->resetAfterTest();
        lmslabs::$testanswers = [];
        lmslabs::$testsent = [];
        set_config('lmslabs_siteid', 'site-123', 'mod_aiinteractivevideo');
        set_config('lmslabs_apikey', 'key-abc', 'mod_aiinteractivevideo');
        $course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module(
            'aiinteractivevideo',
            [
                'course' => $course->id, 'numinteractions' => 2, 'withsections' => false,
            ]
        );
        $this->context = \context_module::instance($this->instance->cmid);
    }

    /**
     * A valid LMS Labs answer: two sections made from the sample transcript.
     *
     * @return string
     */
    private function answer(): string {
        $sections = [
            ['title' => 'The heart as a pump', 'start' => '0:00', 'end' => '0:40', 'type' => 'fillblanks',
                'prompt' => 'Complete the sentence.', 'hint' => 'Think of the chambers.',
                'feedback_correct' => 'Right.', 'feedback_wrong' => 'Listen again.',
                'text' => 'The heart has four [[chambers]] and [[valves]] stop blood flowing backwards.',
                'distractors' => ['bones', 'nerves']],
            ['title' => 'Arteries and veins', 'start' => '0:40', 'end' => '1:20', 'type' => 'matching',
                'prompt' => 'Match each vessel.', 'hint' => 'Away or back?',
                'feedback_correct' => 'Right.', 'feedback_wrong' => 'Listen again.',
                'pairs' => [['left' => 'Arteries', 'right' => 'Carry blood away from the heart'],
                    ['left' => 'Veins', 'right' => 'Bring blood back to the heart'],
                    ['left' => 'Capillaries', 'right' => 'Connect arteries and veins']]],
        ];
        return json_encode(
            ['ok' => true, 'status' => 'completed', 'requestId' => 'r1', 'creditsCharged' => 100,
            'credits' => 900, 'output' => json_encode(['sections' => $sections])]
        );
    }

    /**
     * Creating the activity queues one LMS Labs generation; a successful answer creates the interactions and grade.
     */
    public function test_successful_generation(): void {
        global $DB;
        $this->setup_activity();
        $this->assertEquals(
            manager::GEN_PENDING,
            (int)$DB->get_field(
                'aiinteractivevideo',
                'genstatus',
                ['id' => $this->instance->id]
            )
        );
        $key = manager::generation_key($this->instance->id);
        $this->assertNotEmpty($key);
        \curl::mock_response($this->answer());
        $this->assertEquals(2, manager::run_ai($this->instance));
        $record = $DB->get_record('aiinteractivevideo', ['id' => $this->instance->id]);
        $this->assertEquals(manager::GEN_READY, (int)$record->genstatus);
        $this->assertEquals(2, (float)$record->grade);
        $this->assertSame('', manager::generation_key($this->instance->id));
        // A second run (for example the background task after the teacher's page finished) sends nothing.
        $this->assertEquals(2, manager::run_ai($this->instance));
    }

    /**
     * Not enough credits: nothing charged, the next try is a new request, and the teacher sees the reason.
     */
    public function test_insufficient_credits(): void {
        global $DB;
        $this->setup_activity();
        \curl::mock_response(
            json_encode(
                ['ok' => false, 'error' => 'INSUFFICIENT_CREDITS', 'credits' => 40,
                'buyUrl' => 'https://lms-labs.com/credits']
            )
        );
        try {
            manager::run_ai($this->instance);
            $this->fail('Expected an insufficient credits error');
        } catch (lmslabs_exception $e) {
            $this->assertEquals(lmslabs_exception::INSUFFICIENT, $e->reason);
            $this->assertEquals(40, $e->credits);
            $this->assertFalse($e->may_be_charged());
        }
        $this->assertEquals(
            manager::GEN_FAILED,
            (int)$DB->get_field(
                'aiinteractivevideo',
                'genstatus',
                ['id' => $this->instance->id]
            )
        );
        $this->assertSame('', manager::generation_key($this->instance->id));
        $error = manager::generation_error($this->instance->id);
        $this->assertStringContainsString('40', $error['message']);
        $this->assertEquals('https://lms-labs.com/credits', $error['buyurl']);
        $this->assertEquals(0, $DB->count_records('aiinteractivevideo_section', ['aiinteractivevideoid' => $this->instance->id]));
    }

    /**
     * A purchase link to another host is never shown.
     */
    public function test_foreign_buy_link_is_dropped(): void {
        $this->setup_activity();
        \curl::mock_response(
            json_encode(
                ['ok' => false, 'error' => 'INSUFFICIENT_CREDITS', 'credits' => 0,
                'buyUrl' => 'https://evil.example/pay']
            )
        );
        try {
            manager::run_ai($this->instance);
            $this->fail('Expected an insufficient credits error');
        } catch (lmslabs_exception $e) {
            $this->assertSame('', $e->buyurl);
        }
    }

    /**
     * Still processing on LMS Labs: the same key is kept, so "Try again" can never be charged twice.
     */
    public function test_processing_keeps_the_key_and_retry_reuses_it(): void {
        global $USER;
        $this->setup_activity();
        $this->setAdminUser();
        $key = manager::generation_key($this->instance->id);
        \curl::mock_response(json_encode(['ok' => false, 'status' => 'processing', 'requestId' => 'r1']));
        try {
            manager::run_ai($this->instance);
            $this->fail('Expected a processing error');
        } catch (lmslabs_exception $e) {
            $this->assertEquals(lmslabs_exception::PROCESSING, $e->reason);
            $this->assertTrue($e->may_be_charged());
        }
        $this->assertSame($key, manager::generation_key($this->instance->id));
        // Try again keeps the key; a new generation gets a new one.
        manager::request_generation($this->instance, $this->context, (int)$USER->id, true);
        $this->assertSame($key, manager::generation_key($this->instance->id));
        \curl::mock_response($this->answer());
        $this->assertEquals(2, manager::run_ai($this->instance));
        manager::request_generation($this->instance, $this->context, (int)$USER->id);
        $this->assertNotSame($key, manager::generation_key($this->instance->id));
    }

    /**
     * LMS Labs cannot generate or answers with something unusable: status failed, existing interactions unchanged.
     */
    public function test_generation_failed_and_bad_output(): void {
        global $DB;
        $this->setup_activity();
        \curl::mock_response(json_encode(['ok' => false, 'error' => 'GENERATION_FAILED', 'message' => 'Model busy']));
        try {
            manager::run_ai($this->instance);
            $this->fail('Expected a failure');
        } catch (lmslabs_exception $e) {
            $this->assertEquals(lmslabs_exception::FAILED, $e->reason);
            $this->assertStringContainsString('Model busy', $e->getMessage());
        }
        $this->assertSame('', manager::generation_key($this->instance->id));

        $this->setAdminUser();
        manager::request_generation($this->instance, $this->context, 2);
        \curl::mock_response(json_encode(['ok' => true, 'output' => 'Sorry, I cannot help with that.']));
        try {
            manager::run_ai($this->instance);
            $this->fail('Expected unusable output');
        } catch (lmslabs_exception $e) {
            $this->assertEquals(lmslabs_exception::BADRESPONSE, $e->reason);
        }
        $this->assertEquals(0, $DB->count_records('aiinteractivevideo_section', ['aiinteractivevideoid' => $this->instance->id]));
    }

    /**
     * LMS Labs answers with its website page (the endpoint is not offered yet): clearly reported, nothing charged,
     * and the next try is a new request.
     */
    public function test_endpoint_not_offered(): void {
        global $DB;
        $this->setup_activity();
        \curl::mock_response('<!DOCTYPE html><html><head><title>LMS Labs</title></head><body><div id="root"></div></body></html>');
        try {
            manager::run_ai($this->instance);
            $this->fail('Expected not available');
        } catch (lmslabs_exception $e) {
            $this->assertEquals(lmslabs_exception::NOTAVAILABLE, $e->reason);
            $this->assertFalse($e->may_be_charged());
            $this->assertStringContainsString('No credits were used', $e->getMessage());
        }
        $this->assertSame('', manager::generation_key($this->instance->id));
        $this->assertEquals(
            manager::GEN_FAILED,
            (int)$DB->get_field(
                'aiinteractivevideo',
                'genstatus',
                ['id' => $this->instance->id]
            )
        );
    }

    /**
     * Without credentials nothing is sent and the teacher is told to ask the administrator.
     */
    public function test_not_configured(): void {
        global $DB;
        $this->setup_activity();
        unset_config('lmslabs_apikey', 'mod_aiinteractivevideo');
        $this->assertFalse(lmslabs::configured());
        $this->setAdminUser();
        try {
            manager::request_generation($this->instance, $this->context, 2);
            $this->fail('Expected a not configured error');
        } catch (lmslabs_exception $e) {
            $this->assertEquals(lmslabs_exception::NOTCONFIGURED, $e->reason);
        }
        $this->assertEquals(
            manager::GEN_FAILED,
            (int)$DB->get_field(
                'aiinteractivevideo',
                'genstatus',
                ['id' => $this->instance->id]
            )
        );
        $this->assertStringContainsString('Site ID and API key', manager::generation_error($this->instance->id)['message']);
    }

    /**
     * Balance: unlimited sites and numeric balances; the balance never breaks the form when LMS Labs is unknown.
     */
    public function test_balance(): void {
        $this->setup_activity();
        \curl::mock_response(json_encode(['credits' => 'Unlimited', 'creditsRaw' => -1, 'isUnlimited' => true]));
        $this->assertEquals(['unlimited' => true, 'credits' => null], lmslabs::balance());
        \curl::mock_response(json_encode(['credits' => 250, 'creditsRaw' => 250, 'isUnlimited' => false]));
        $this->assertEquals(['unlimited' => false, 'credits' => 250], lmslabs::balance());
        \curl::mock_response('not json');
        $this->assertNull(lmslabs::balance());
    }

    /**
     * The UUID and the exact request body are stored before the first call, and that body is what is sent,
     * even if the activity is edited before the request runs.
     */
    public function test_request_stored_before_first_call(): void {
        global $DB;
        $this->setup_activity();
        $key = manager::generation_key($this->instance->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key);
        $this->assertSame([], lmslabs::$testsent, 'Nothing is sent when the request is stored');
        $stored = manager::stored_request($this->context, $key);
        $this->assertNotNull($stored);
        $request = json_decode($stored, true);
        $this->assertSame(
            ['siteId', 'pluginId', 'usageType', 'idempotencyKey', 'prompt', 'metadata'],
            array_keys($request)
        );
        $this->assertSame('site-123', $request['siteId']);
        $this->assertSame('mod_aiinteractivevideo', $request['pluginId']);
        $this->assertSame('generate_activity', $request['usageType']);
        $this->assertSame($key, $request['idempotencyKey']);
        $this->assertSame(
            ['instanceId', 'interactions', 'types', 'transcriptWords', 'videoId', 'pluginRelease', 'moodleRelease'],
            array_keys($request['metadata'])
        );
        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');
        $this->assertSame($plugin->release, $request['metadata']['pluginRelease']);
        // Editing the activity afterwards does not change the pending request.
        $edited = "0:00 Something else entirely.\n0:10 And more.";
        $DB->set_field('aiinteractivevideo', 'transcript', $edited, ['id' => $this->instance->id]);
        lmslabs::$testanswers = [[200, $this->answer()]];
        $this->assertEquals(2, manager::run_ai($this->instance));
        $this->assertCount(1, lmslabs::$testsent);
        $this->assertSame($stored, lmslabs::$testsent[0]['body']);
        $this->assertSame($key, lmslabs::$testsent[0]['idempotencykey']);
        $this->assertNull(manager::stored_request($this->context, $key), 'Finished requests are removed');
    }

    /**
     * Retry-key retention: after a timeout, 202, "processing", JSON 5xx with an error field and 409, the same UUID
     * and the same bytes are sent again, no new UUID is made (a new generation is refused), and the completed
     * replay creates the activities.
     */
    public function test_retry_key_retention(): void {
        global $DB, $USER;
        $this->setup_activity();
        $this->setAdminUser();
        $key = manager::generation_key($this->instance->id);
        $stored = manager::stored_request($this->context, $key);
        $answers = [
            'timeout' => [0, ''],
            'accepted' => [202, json_encode(['ok' => true, 'status' => 'processing', 'requestId' => 'r1'])],
            'processing200' => [200, json_encode(['ok' => false, 'status' => 'processing', 'error' => 'IN_PROGRESS'])],
            'json500' => [500, json_encode(['ok' => false, 'error' => 'INTERNAL_ERROR', 'message' => 'Upstream failed'])],
            'json503' => [503, json_encode(['ok' => false, 'status' => 'processing', 'error' => 'BUSY'])],
            'html502' => [502, '<html>Bad gateway</html>'],
            'conflict' => [409, json_encode(['ok' => false, 'error' => 'IDEMPOTENCY_CONFLICT'])],
        ];
        $expected = [
            'timeout' => lmslabs_exception::UNREACHABLE, 'accepted' => lmslabs_exception::PROCESSING,
            'processing200' => lmslabs_exception::PROCESSING, 'json500' => lmslabs_exception::UNREACHABLE,
            'json503' => lmslabs_exception::PROCESSING, 'html502' => lmslabs_exception::UNREACHABLE,
            'conflict' => lmslabs_exception::CONFLICT,
        ];
        foreach ($answers as $name => $answer) {
            lmslabs::$testanswers = [$answer];
            try {
                manager::run_ai($this->instance);
                $this->fail("Expected a failure for $name");
            } catch (lmslabs_exception $e) {
                $this->assertSame($expected[$name], $e->reason, $name);
                $this->assertTrue($e->may_be_charged(), $name);
            }
            $this->assertSame($key, manager::generation_key($this->instance->id), "$name keeps the UUID");
            $this->assertSame($stored, manager::stored_request($this->context, $key), "$name keeps the body");
            $this->assertEquals(
                manager::GEN_FAILED,
                (int)$DB->get_field('aiinteractivevideo', 'genstatus', ['id' => $this->instance->id])
            );
            // No new UUID is made automatically: a new generation is refused until this one is finished.
            try {
                manager::request_generation($this->instance, $this->context, (int)$USER->id);
                $this->fail("Expected a new generation to be refused after $name");
            } catch (lmslabs_exception $e) {
                $this->assertSame(lmslabs_exception::UNRESOLVED, $e->reason);
            }
            $this->assertSame($key, manager::generation_key($this->instance->id));
            // Try again keeps the UUID and the body.
            manager::request_generation($this->instance, $this->context, (int)$USER->id, true);
            $this->assertSame($key, manager::generation_key($this->instance->id));
        }
        // The completed replay: stored output, no further request needed.
        $replay = json_decode($this->answer(), true);
        $replay['replayed'] = true;
        lmslabs::$testanswers = [[200, json_encode($replay)]];
        $this->assertEquals(2, manager::run_ai($this->instance));
        $this->assertSame('', manager::generation_key($this->instance->id));
        $this->assertCount(count($answers) + 1, lmslabs::$testsent);
        foreach (lmslabs::$testsent as $sent) {
            $this->assertSame($key, $sent['idempotencykey']);
            $this->assertSame($stored, $sent['body']);
        }
        // Once finished, a new generation gets a new UUID.
        manager::request_generation($this->instance, $this->context, (int)$USER->id);
        $this->assertNotSame($key, manager::generation_key($this->instance->id));
    }

    /**
     * Insufficient credits come as an HTTP 200 error answer: not a success, nothing charged, the key ends.
     * Not entitled (HTTP 403) is reported clearly and nothing is charged.
     */
    public function test_definite_refusals(): void {
        global $DB;
        $this->setup_activity();
        lmslabs::$testanswers = [[200, json_encode(['ok' => false, 'error' => 'INSUFFICIENT_CREDITS', 'credits' => 40])]];
        try {
            manager::run_ai($this->instance);
            $this->fail('Expected insufficient credits');
        } catch (lmslabs_exception $e) {
            $this->assertSame(lmslabs_exception::INSUFFICIENT, $e->reason);
        }
        $this->assertSame('', manager::generation_key($this->instance->id));
        $this->assertEquals(0, $DB->count_records('aiinteractivevideo_section', ['aiinteractivevideoid' => $this->instance->id]));

        $this->setAdminUser();
        manager::request_generation($this->instance, $this->context, 2);
        lmslabs::$testanswers = [[403, json_encode(['ok' => false, 'error' => 'NOT_ENTITLED'])]];
        try {
            manager::run_ai($this->instance);
            $this->fail('Expected not entitled');
        } catch (lmslabs_exception $e) {
            $this->assertSame(lmslabs_exception::NOTENTITLED, $e->reason);
            $this->assertFalse($e->may_be_charged());
        }
        $this->assertSame('', manager::generation_key($this->instance->id));
    }

    /**
     * If the site's Site ID changes while a request is unfinished, nothing is sent and the request is kept.
     */
    public function test_site_changed_sends_nothing(): void {
        $this->setup_activity();
        $key = manager::generation_key($this->instance->id);
        set_config('lmslabs_siteid', 'another-site', 'mod_aiinteractivevideo');
        try {
            manager::run_ai($this->instance);
            $this->fail('Expected site changed');
        } catch (lmslabs_exception $e) {
            $this->assertSame(lmslabs_exception::SITECHANGED, $e->reason);
        }
        $this->assertSame([], lmslabs::$testsent);
        $this->assertSame($key, manager::generation_key($this->instance->id));
    }
}
