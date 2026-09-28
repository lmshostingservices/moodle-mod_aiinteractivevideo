# AI interactive video (mod_aiinteractivevideo)

AI interactive video turns any YouTube video into an interactive lesson in a couple of minutes. The teacher pastes the video link and the transcript, chooses how many interactions they want, and saves. The transcript is divided into sections where the topic changes, and every section ends with a fun, modern activity made from what was said in that section.

While learners watch, the video pauses at the end of each section and the activity appears beside it. A correct answer continues the video. A wrong answer explains what to listen for and replays the section from its start, so learners hear it again before trying again. Each interaction is worth one point in the gradebook.

## Features

**Learner experience**
- One soft grey card that fills the page: the YouTube video on the left and the activities on the right. On phones the video sits on top.
- A full screen button that scales the whole card, including the activities, to the screen (with a fallback for iPhone Safari).
- Premium light design with seven accent colours, animated transitions, confetti, particle bursts, a drawn green tick for correct answers and floating "+1" points.
- Sound effects generated in the browser (no audio files), which learners can mute and teachers can switch off.
- Custom video controls: play/pause, a timeline with a marker for every interaction (green when solved), section ticks, hover tips, playback speed and mute.
- "Your turn!" overlay when the video pauses, an "Up next" countdown before each interaction, and a live list of sections with their status.
- No skipping ahead to parts not yet watched (optional). Attempts resume where the learner left off.
- Feedback for every answer: each item is marked right or wrong, with an explanation.
- Results screen: animated score ring, headline, time, correct first time, replays and hints, a breakdown of every interaction, and a leaderboard (points, then fastest time; names shown as first name and last initial).

**Eight interaction types**

| Type | What learners do |
|---|---|
| Card select | Tap every card that fits (e.g. the three reasons given in the video). |
| Category sort | Drag or tap cards into two to four groups. |
| Fill the gaps | Drop words from a word bank into gaps in a quote. |
| Sequence | Drag steps into the right order (or use the arrows). |
| Match up | Connect terms to what they mean; animated coloured lines join the pairs. |
| Spot the mistake | Tap the wrong word in a quote; the correction appears above it. |
| Fact or fiction | Swipe a deck of statement cards right (fact) or left (fiction), or use the buttons. |
| Word builder | Tap letter tiles to build a key term from a clue. |

All types work with mouse, touch and keyboard, and respect "reduce motion".

**Two modes**

| | Learn | Test |
|---|---|---|
| Hint | Yes: shows a tip and removes wrong options or fills in part of the answer | No |
| Show answer | Yes | No |
| Wrong answer | Explains and replays the section | Explains and replays the section; after the try limit the learner moves on |
| Graded | Only when Test mode is off | Yes |

A point is earned when the interaction is answered correctly first time without a hint (or, if the teacher chooses, correctly on any try without a hint or showing the answer).

**Building the interactions from the transcript**
- *LMS Labs AI*: when the teacher saves, the timed transcript, the number of activities and the allowed types are sent to LMS Labs AI with instructions to follow the real topic changes and to base every activity only on what is said. Any activity that comes back invalid is rebuilt from the same stretch of transcript. Each creation uses 100 LMS Labs AI credits (shown on the form before saving); editing, playback and learner attempts are free. Every generation request carries an idempotency key, so a retry after a timeout never charges twice.
- Transcript formats: the YouTube "Show transcript" copy (timestamps on their own line or at the start of a line), SRT, WebVTT, bracketed timestamps, or plain text (timing is then spread over the video).

**Teacher tools**
- Interaction builder: a timeline of the sections, every interaction with its content, hint and feedback, Edit and Delete, Add (once the activities have been created), Try again after a failed creation, and Create again with AI.
- Edit any interaction in a simple text format (for example `* ` marks correct cards, `[[word]]` marks a gap, `{{wrong|right}}` marks a mistake).
- Reports:
  - *Overview*: learners, attempts, average score and time; for each interaction the share right first time, average tries, average time, replays, hints and answers shown, with flags for "Too hard", "Takes too long" and "Often rewatched".
  - *Attempts*: every attempt with points, active time, replays and hints; drill down into one attempt; delete attempts (grades and completion are recalculated), recalculate grades, and download as CSV, Excel or ODS.
  - Group filter and Learn/Test switch.

**Moodle integration**
- Gradebook (one point per interaction), grade to pass, grading method and attempt limit.
- Completion: view, receive a grade, passing grade and the custom rule "Finish the video".
- Backup and restore including user data, course reset, events, Privacy API.

## Requirements
- Moodle 4.4 or later.
- An LMS Labs account. The Site ID and API key are read from LMS Labs Central Config (`local_aiconfig`, *Site administration > Plugins > Local plugins > AI Grader Central Config*) when both are set there; otherwise enter both in *Site administration > Plugins > Activity modules > AI interactive video*. A central and a plugin value are never mixed. The site must be able to reach https://lms-labs.com.
- Learners' browsers must be able to reach YouTube (www.youtube.com and www.youtube-nocookie.com).

## Installation
1. Unzip into `mod/aiinteractivevideo`. On Moodle 5.1 and later this is `public/mod/aiinteractivevideo`.
2. Visit *Site administration > Notifications*.
3. Make sure LMS Labs Central Config has this site's Site ID and API key (or enter both in the plugin settings), and optionally set the defaults in *Site administration > Plugins > Activity modules > AI interactive video*.
4. Add an **AI interactive video** activity to a course.

## Getting the transcript from YouTube
Open the video on YouTube, select *...more* under the video, then *Show transcript*. Select all the transcript text and paste it into the activity settings. The timestamps are used to place the pauses.

## Capabilities
| Capability | Default roles |
|---|---|
| `mod/aiinteractivevideo:addinstance` | Editing teacher, Manager |
| `mod/aiinteractivevideo:view` | Guest, Student, Teacher, Editing teacher, Manager |
| `mod/aiinteractivevideo:attempt` | Student |
| `mod/aiinteractivevideo:manage` | Editing teacher, Manager |
| `mod/aiinteractivevideo:viewreports` | Teacher, Editing teacher, Manager |

## Privacy
- Attempts and per-interaction results are stored in Moodle and covered by the Privacy API (export and delete).
- Videos play in the YouTube embedded player in privacy-enhanced mode (youtube-nocookie.com); the learner's browser connects to YouTube directly.
- To create the activities, the transcript and activity settings are sent to LMS Labs AI with the site's LMS Labs Site ID. No information about learners or teachers is sent. The API key stays on the server.

## Security
- Answers never reach the browser. Each attempt has its own random tokens and every response is marked on the server.
- All actions use Moodle external services with context, capability and ownership checks.

## Development
- JavaScript source is in `amd/src` and the built files in `amd/build`. Rebuild with `npx grunt amd --root=mod/aiinteractivevideo` from a Moodle checkout.
- PHPUnit: `vendor/bin/phpunit --testsuite mod_aiinteractivevideo_testsuite`.

## Licence
© 2026 LMS Hosting Services. GNU GPL v3 or later.
