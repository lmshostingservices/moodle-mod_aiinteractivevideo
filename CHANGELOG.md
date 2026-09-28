# Changelog

All notable changes to AI interactive video (mod_aiinteractivevideo) are documented here.

## [v1.0.8] - 2026-09-28

### Fixed
- Include Moodle's required db/upgrade.php in the archive so Marketplace accepts the package. No database schema change or migration is needed.

## [v1.0.7] - 2026-09-28

### Added
- Administrator-only activation page linked from plugin settings: free access check, live release price and balance, explicit price-and-release-bound confirmation for a one-time site unlock.
- Pending purchase recovery via a manual access check; uncertain outcomes cannot automatically retry or charge again.

### Unchanged
- Video generation, its 100-credit tariff and its UUID/same-body recovery protocol are unchanged.

## [v1.0.6] - 2026-09-28

### Changed
- LMS Labs generation recovery follows the server runbook contract:
  - The idempotency key (a UUID) and the exact request body are stored before the first call, and every retry sends the same key and the same bytes. Editing the activity does not change an unfinished request.
  - The key and body are kept after a network failure or timeout, HTTP 202, any "processing" answer, any HTTP 5xx answer (even with an error field) and HTTP 409. They are only cleared after success or a definite refusal (insufficient credits, not entitled, bad key, bad request, generation failed).
  - A new generation is refused while an earlier request is unfinished, so a new key is never made automatically after an unknown outcome. The teacher finishes it with "Try again" first (a completed request is replayed without another debit).
  - HTTP 409 (key/payload conflict) and HTTP 403 (site not entitled to AI interactive video) have their own messages.
  - If the site's LMS Labs Site ID changes while a request is unfinished, nothing is sent.
### Fixed
- The pluginRelease metadata value sent to LMS Labs was empty; it now carries this release (for example 1.0.6). Same field name.

### Unchanged
- No change to the endpoint, headers, body fields, metadata field names or the 100-credit tariff. Moodle never debits credits itself.

## [v1.0.5] - 2026-09-28

### Changed
- Release pipeline test build. No functional changes from 1.0.4.

## [v1.0.4] - 2026-09-28

### Changed
- Full screen shows everything at once, with no scroll bars: an activity, its hints and feedback, the home screen and the results always fit on one screen. Anything taller than its space is shrunk just enough to fit, and on the home screen the video makes room for the interaction list.

### Fixed
- Themes that colour every button or heading (for example white button text or orange headings) no longer make activity text invisible or change its colour: Match up cards were showing blank. Buttons and headings inside the activity now always use the activity's own colours.

## [v1.0.3] - 2026-09-27

### Fixed
- The course index page (list of AI interactive videos) now uses require_login() and checks the view capability.
- Coding style: multi-line function calls now open with the parenthesis at the end of the line, one argument per line.

## [v1.0.2] - 2026-09-27

### Fixed
- When LMS Labs does not offer AI interactive video creation yet (its answer is not an API response), teachers now see that clearly instead of "LMS Labs AI returned activities that could not be used". Nothing is charged, and the next try is a new request.

## [v1.0.1] - 2026-09-27

### Changed
- The activities are now created automatically by LMS Labs AI when the teacher saves the video link, transcript, number of activities and allowed types. Each creation uses 100 LMS Labs AI credits, shown on the form with the site's balance before saving. Every request carries an idempotency key, so a retry after a timeout is never charged twice.
- LMS Labs credentials come from LMS Labs Central Config (local_aiconfig) when it has both the Site ID and the API key, otherwise from a complete pair in this plugin's settings. The two are never mixed and central values are never copied.
- Interaction builder: Add appears once the activities exist; Try again after a failed creation (the reason and, when credits run out, a link to buy credits are shown); Create again with AI after a confirmation.
- Full screen now scales the whole card to the screen like a presentation: the video is sized to fit, the activity panel runs full height with its buttons pinned, and dragging works at any scale.

### Removed
- The free built-in generator, "Use your own AI assistant" (paste an AI answer) and the Moodle AI subsystem route.

### Fixed
- "Error reading from database" when a learner pressed Start on MySQL and MariaDB sites.
- An AI failure (for example a refused connection) no longer leaves the activity stuck on "building".
- Section titles with "&" were shown escaped; names split across two key terms are kept whole.
- Report score bars now show without JavaScript; small layout and style fixes.

## [v1.0.0] - 2026-09-26

### Added
- New activity: turn a YouTube video and its transcript into an interactive lesson.
- The transcript is divided into sections at the topic changes, with one interaction per section. The video pauses at the end of each section; a wrong answer replays the section from its start.
- Eight interaction types: Card select, Category sort, Fill the gaps, Sequence, Match up, Spot the mistake, Fact or fiction (swipe deck) and Word builder. Drag and drop, tap-tap and keyboard support.
- Two ways to build the interactions: the built-in generator (instant, no AI needed, built from the transcript itself) or the Moodle AI subsystem (Moodle 4.5 and later). Teachers can also copy a prompt into any AI assistant and paste the JSON answer back.
- Learn mode (Hint, Show answer, friendly feedback) and Test mode (graded, try limit).
- One point per interaction; the maximum grade is the number of interactions. Grading methods: highest, average, first, last. Attempt limit.
- Results screen with animated score ring, per-interaction breakdown, confetti and a leaderboard.
- Custom video controls with section markers, no skipping ahead (optional), resume where you left off, playback speed.
- Full screen for the whole card, premium light theme with seven accent colours, synthesised sound effects (can be muted).
- Interaction builder: review, edit, add or delete interactions, rebuild, and import from any AI assistant.
- Reports: interactions that are too hard, take too long or are often replayed; every attempt with drill-down; CSV, Excel and ODS download; delete attempts and recalculate grades.
- Completion rule "Finish the video", events, backup and restore (with user data), course reset and Privacy API.
