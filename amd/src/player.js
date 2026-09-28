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
 * AI interactive video player: YouTube on the left, interactions on the right, all in one card.
 *
 * The video pauses at the end of each section and the section's interaction appears. A correct answer continues
 * the video; a wrong answer replays the section from its start. Learn mode adds Hint and Show answer. Every
 * response is marked on the server.
 *
 * @module     mod_aiinteractivevideo/player
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {getStrings} from 'core/str';
import * as Sound from 'mod_aiinteractivevideo/sound';
import * as Fx from 'mod_aiinteractivevideo/fx';
import {Video} from 'mod_aiinteractivevideo/youtube';
import * as Interactions from 'mod_aiinteractivevideo/interactions';

const COMPONENT = 'mod_aiinteractivevideo';
const STRING_KEYS = [
    'play', 'pause', 'yourturn', 'interactionof', 'pointsearned', 'wrong', 'skipped', 'continuevideo', 'finish',
    'countdown_rewatch', 'triesleft', 'hintnopoint', 'skipblocked', 'nextup', 'selectedof', 'fact', 'fiction',
    'moveup', 'movedown', 'fullscreen', 'exitfullscreen', 'mute', 'unmute', 'learnmode', 'testmode',
    'intro_learn_title', 'intro_learn_sub', 'intro_test_title', 'intro_test_sub', 'intro_interactions', 'intro_video',
    'intro_hints', 'intro_nohints', 'intro_tries', 'intro_graded', 'intro_attempts', 'intro_unlimited', 'intro_resume',
    'howitworks_3', 'cardselect', 'categorize', 'fillblanks', 'ordering', 'matching', 'spotmistake', 'swipe',
    'unscramble', 'cardselect_how', 'categorize_how', 'fillblanks_how', 'ordering_how', 'matching_how',
    'spotmistake_how', 'swipe_how', 'unscramble_how', 'excellent', 'greatjob', 'goodeffort', 'keeppractising',
    'correctof', 'status_firsttry', 'status_solved', 'status_hinted', 'status_revealed', 'status_skipped', 'status_notanswered',
    'passmark_met', 'passmark_notmet', 'praise1', 'praise2', 'praise3', 'praise4', 'answerrevealed', 'rewatching',
    'missing', 'speed', 'progressof', 'buildfailed', 'intro_pass', 'wrong_learn', 'wrong_test', 'lasttry',
    'rewatchnow', 'videoerror',
];

let S = {};

/**
 * Loads strings.
 *
 * @returns {Promise}
 */
const loadStrings = async() => {
    const values = await getStrings(STRING_KEYS.map((key) => ({key, component: COMPONENT})));
    STRING_KEYS.forEach((key, i) => {
        S[key] = values[i];
    });
};

/**
 * Replaces {$a} and {$a->name} placeholders.
 *
 * @param {string} str
 * @param {*} a
 * @returns {string}
 */
const fmt = (str, a) => {
    if (a !== null && typeof a === 'object') {
        return String(str).replace(/\{\$a->(\w+)\}/g, (m, k) => (a[k] ?? ''));
    }
    return String(str).replace(/\{\$a\}/g, a);
};

/**
 * Formats seconds as m:ss.
 *
 * @param {number} secs
 * @returns {string}
 */
const clock = (secs) => {
    secs = Math.max(0, Math.floor(secs || 0));
    const h = Math.floor(secs / 3600);
    const m = Math.floor((secs % 3600) / 60);
    const s = String(secs % 60).padStart(2, '0');
    return h ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
};

/**
 * Calls a web service.
 *
 * @param {string} name without the component prefix
 * @param {object} args
 * @returns {Promise}
 */
const call = (name, args) => Ajax.call([{methodname: `${COMPONENT}_${name}`, args}])[0];

/**
 * The player.
 */
class Player {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root
     * @param {object} config
     */
    constructor(root, config) {
        this.root = root;
        this.config = config;
        const q = (r) => root.querySelector(`[data-region="${r}"]`);
        this.el = {
            shell: q('shell'), stage: q('stage'), screen: q('screen'), video: q('video'), poster: q('poster'),
            overlay: q('overlay'), toast: q('toast'), controls: q('controls'), timeline: q('timeline'),
            watched: q('watched'), played: q('played'), playhead: q('playhead'), markers: q('markers'), tip: q('tip'),
            time: q('time'), duration: q('duration'), panel: q('panel'), home: q('home'), dynamic: q('dynamic'),
            results: q('results'), live: q('live'), score: q('score'), scorevalue: q('scorevalue'),
            scoretotal: q('scoretotal'), timer: q('timer'), timervalue: q('timervalue'), modetag: q('modetag'),
        };
        this.video = null;
        this.data = null;
        this.mode = null;
        this.open = null;
        this.maxwatched = 0;
        this.active = 0;
        this.unsaved = 0;
        this.speeds = [1, 1.25, 1.5, 1.75, 0.75];
        Sound.setAllowed(!!config.sounds);
        this.syncMute();
        root.addEventListener('click', (e) => this.onClick(e));
        document.addEventListener('keydown', (e) => this.onKey(e));
        document.addEventListener('fullscreenchange', () => this.syncFullscreen());
        document.addEventListener('webkitfullscreenchange', () => this.syncFullscreen());
        window.addEventListener('resize', () => {
            if (this.el.shell.classList.contains('is-full')) {
                this.fitFullscreen();
            }
        });
        this.watchFit();
        this.bindTimeline();
        window.addEventListener('pagehide', () => this.saveProgress());
        if (config.pending && config.canmanage) {
            this.buildWithAi();
        }
    }

    /**
     * Announces text to screen readers.
     *
     * @param {string} msg
     */
    say(msg) {
        this.el.live.textContent = '';
        window.setTimeout(() => {
            this.el.live.textContent = msg;
        }, 40);
    }

    /**
     * Shows a short message over the video.
     *
     * @param {string} msg
     * @param {string} tone info|warn
     */
    toast(msg, tone = 'info') {
        const t = this.el.toast;
        t.textContent = msg;
        t.className = `aiv-toast is-${tone}`;
        t.hidden = false;
        window.clearTimeout(this.toastTimer);
        this.toastTimer = window.setTimeout(() => {
            t.hidden = true;
        }, 2600);
    }

    /**
     * Click delegation.
     *
     * @param {MouseEvent} e
     */
    onClick(e) {
        const btn = e.target.closest('[data-action]');
        if (!btn || btn.disabled || !this.root.contains(btn)) {
            return;
        }
        const action = btn.dataset.action;
        const handlers = {
            start: () => this.showIntro(btn.dataset.mode),
            poster: () => this.posterClick(),
            introback: () => this.backHome(),
            go: () => this.start(this.mode),
            mute: () => {
                Sound.toggleMute();
                this.syncMute();
            },
            fullscreen: () => this.toggleFullscreen(),
            exit: () => this.exit(),
            toggleplay: () => this.togglePlay(),
            speed: () => this.cycleSpeed(btn),
            videomute: () => this.toggleVideoMute(btn),
            chapter: () => this.jumpToChapter(parseInt(btn.dataset.index, 10)),
            check: () => this.check(),
            hint: () => this.hint(btn),
            reveal: () => this.reveal(),
            'continue': () => this.continueVideo(),
            rewatch: () => this.rewatch(),
            retry: () => this.retry(),
            home: () => this.reload(),
        };
        if (handlers[action]) {
            e.preventDefault();
            handlers[action]();
        }
    }

    /**
     * Keyboard shortcuts.
     *
     * @param {KeyboardEvent} e
     */
    onKey(e) {
        if (!this.data || this.el.stage.hidden) {
            return;
        }
        const tag = (e.target.tagName || '').toLowerCase();
        if (['input', 'textarea', 'select'].includes(tag) || e.target.isContentEditable) {
            return;
        }
        if (e.key === 'Escape' && this.el.shell.classList.contains('is-pseudo-full')) {
            this.toggleFullscreen();
            return;
        }
        if (this.open || !this.el.shell.contains(document.activeElement) && document.activeElement !== document.body) {
            return;
        }
        if (e.key === ' ' || e.key === 'k') {
            if (tag === 'button') {
                return;
            }
            e.preventDefault();
            this.togglePlay();
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
            e.preventDefault();
            this.seekTo(this.video.time() + (e.key === 'ArrowRight' ? 5 : -5));
        }
    }

    /* ---------------------------------------------------------------------------------------------------- */
    /* Home, intro                                                                                          */
    /* ---------------------------------------------------------------------------------------------------- */

    /**
     * The poster starts the first available mode.
     */
    posterClick() {
        const first = this.root.querySelector('[data-action="start"]:not([disabled])');
        if (first) {
            this.showIntro(first.dataset.mode);
        }
    }

    /**
     * Shows the start screen for a mode.
     *
     * @param {string} mode learn|test
     */
    async showIntro(mode) {
        Sound.play('select');
        this.mode = mode;
        const c = this.config;
        const learn = mode === 'learn';
        const items = [
            {ic: {star: true}, text: fmt(S.intro_interactions, c.count), key: true},
        ];
        if (c.duration) {
            items.push({ic: {video: true}, text: fmt(S.intro_video, c.durationtext)});
        }
        items.push({ic: {repeat: true}, text: S.howitworks_3});
        if (learn) {
            items.push({ic: {bulb: true}, text: S.intro_hints});
        } else {
            items.push({ic: {bolt: true}, text: S.intro_nohints});
            if (c.maxtries) {
                items.push({ic: {target: true}, text: fmt(S.intro_tries, c.maxtries)});
            }
        }
        if (mode === c.gradedmode) {
            items.push({ic: {trophy: true}, text: S.intro_graded, key: true});
            if (c.passpoints) {
                items.push({ic: {flag: true}, text: fmt(S.intro_pass, {pass: c.passpoints, total: c.count})});
            }
            if (c.maxattempts) {
                items.push({ic: {clipboard: true}, text: fmt(S.intro_attempts, {left: c.attemptsleft, max: c.maxattempts})});
            }
        }
        const resume = !!(c.resume && c.resume[mode]);
        if (resume) {
            items.unshift({ic: {play: true}, text: S.intro_resume, key: true});
        }
        await this.renderInto(this.el.dynamic, 'player_intro', {
            mode,
            badge: {ic: learn ? {book: true} : {clipboard: true}},
            modename: learn ? S.learnmode : S.testmode,
            title: learn ? S.intro_learn_title : S.intro_test_title,
            sub: learn ? S.intro_learn_sub : S.intro_test_sub,
            items,
            resume,
        });
        this.el.home.hidden = true;
        this.el.dynamic.hidden = false;
        this.el.panel.classList.add('is-intro');
        const go = this.el.dynamic.querySelector('[data-action="go"]');
        if (go) {
            go.focus({preventScroll: true});
        }
    }

    /**
     * Back from the intro to the mode cards.
     */
    backHome() {
        this.el.dynamic.hidden = true;
        this.el.dynamic.replaceChildren();
        this.el.home.hidden = false;
        this.el.panel.classList.remove('is-intro');
    }

    /**
     * Renders a template into a region.
     *
     * @param {HTMLElement} region
     * @param {string} name template name without component
     * @param {object} context
     * @returns {Promise}
     */
    async renderInto(region, name, context) {
        const {html, js} = await Templates.renderForPromise(`${COMPONENT}/${name}`, context);
        Templates.replaceNodeContents(region, html, js);
    }

    /* ---------------------------------------------------------------------------------------------------- */
    /* Attempt and video                                                                                    */
    /* ---------------------------------------------------------------------------------------------------- */

    /**
     * Starts or resumes an attempt.
     *
     * @param {string} mode
     */
    async start(mode) {
        const go = this.el.dynamic.querySelector('[data-action="go"]');
        if (go) {
            go.disabled = true;
        }
        Sound.play('whoosh');
        try {
            this.data = await call('start_attempt', {cmid: this.config.cmid, mode});
        } catch (err) {
            if (go) {
                go.disabled = false;
            }
            Notification.exception(err);
            return;
        }
        this.mode = mode;
        this.sections = this.data.sections;
        this.maxwatched = this.data.maxwatched || 0;
        this.active = this.data.activetime || 0;
        this.el.shell.classList.add('is-playing-mode', `is-mode-${mode}`);
        this.el.modetag.hidden = false;
        this.el.modetag.textContent = mode === 'learn' ? S.learnmode : S.testmode;
        this.el.modetag.className = `aiv-modetag aiv-modetag-${mode}`;
        this.root.querySelector('[data-action="exit"]').hidden = false;
        this.el.score.hidden = false;
        this.el.timer.hidden = false;
        this.updateScore(this.data.score, false);
        await this.showChapters();
        this.startTimers();
        await this.ensureVideo();
        // Resume at the start of the first unfinished section.
        const next = this.nextPending();
        const at = next ? next.start : Math.max(0, this.data.position || 0);
        if (next && next.start > this.maxwatched) {
            this.maxwatched = next.start;
        }
        this.video.seek(at);
        this.el.poster.hidden = true;
        this.el.controls.hidden = false;
        this.video.play();
        this.say(this.config.name);
    }

    /**
     * Creates the YouTube player once.
     *
     * @returns {Promise}
     */
    ensureVideo() {
        if (this.video) {
            return this.video.promise;
        }
        this.video = new Video(this.el.video, this.config.videoid, {
            onState: (state) => this.onState(state),
            onReady: () => this.onReady(),
            onError: () => this.toast(S.videoerror, 'warn'),
        });
        return this.video.promise.catch((err) => {
            Notification.exception(err);
        });
    }

    /**
     * Player ready: draw markers.
     */
    onReady() {
        this.duration = this.video.duration() || this.config.duration || 0;
        this.el.duration.textContent = clock(this.duration);
        this.el.timeline.setAttribute('aria-valuemax', String(Math.round(this.duration)));
        this.drawMarkers();
    }

    /**
     * Player state changes.
     *
     * @param {string} state
     */
    onState(state) {
        this.el.shell.classList.toggle('is-video-playing', state === 'playing');
        const btn = this.root.querySelector('[data-action="toggleplay"]');
        if (btn) {
            btn.setAttribute('aria-label', state === 'playing' ? S.pause : S.play);
        }
        if (state === 'playing' && this.open) {
            // Nothing plays while an interaction is open.
            this.video.pause();
        }
        if (state === 'ended' && this.data && !this.open) {
            const next = this.nextPending();
            if (next) {
                this.openInteraction(next);
            } else {
                this.finish();
            }
        }
    }

    /**
     * The first section that still needs an answer.
     *
     * @returns {object|undefined}
     */
    nextPending() {
        return (this.sections || []).find((s) => s.status === 'pending');
    }

    /**
     * Where the video pauses for a section.
     *
     * @param {object} section
     * @returns {number}
     */
    pauseAt(section) {
        const d = this.duration || 0;
        return d > 0 ? Math.min(section.end, d - 0.4) : section.end;
    }

    /**
     * Starts the playback watcher, active timer and autosave.
     */
    startTimers() {
        window.clearInterval(this.tickHandle);
        window.clearInterval(this.secondHandle);
        this.tickHandle = window.setInterval(() => this.tick(), 200);
        this.secondHandle = window.setInterval(() => {
            if (document.visibilityState === 'visible' && (this.open || (this.video && this.video.playing()))) {
                this.active++;
                this.unsaved++;
                this.el.timervalue.textContent = clock(this.active);
            }
            if (this.unsaved >= 15) {
                this.saveProgress();
            }
        }, 1000);
        this.el.timervalue.textContent = clock(this.active);
    }

    /**
     * Saves the position and active time.
     */
    saveProgress() {
        if (!this.data || !this.video || this.finished) {
            return;
        }
        const elapsed = this.unsaved;
        this.unsaved = 0;
        call('save_progress', {
            attemptid: this.data.attemptid,
            position: Math.round(this.video.time() * 100) / 100,
            elapsed,
            duration: Math.round(this.duration || 0),
        }).catch(() => {
            this.unsaved += elapsed;
        });
    }

    /**
     * Watches playback: pauses for interactions and stops skipping ahead.
     */
    tick() {
        if (!this.video || !this.video.ready || !this.data || this.finished) {
            return;
        }
        if (!this.duration) {
            this.onReady();
        }
        const t = this.video.time();
        const playing = this.video.playing();
        this.drawProgress(t);
        if (this.open) {
            return;
        }
        if (this.config.preventskip && t > this.maxwatched + 2.5) {
            this.video.seek(this.maxwatched);
            this.toast(S.skipblocked, 'warn');
            return;
        }
        if (playing) {
            this.maxwatched = Math.max(this.maxwatched, t);
        }
        const next = this.nextPending();
        if (!next) {
            if (this.duration && t >= this.duration - 0.6) {
                this.finish();
            }
            this.hideUpNext();
            return;
        }
        const at = this.pauseAt(next);
        if (t >= at - 0.1 && t < next.end + 3) {
            this.openInteraction(next);
            return;
        }
        if (t > next.end + 3) {
            // Jumped past an unanswered interaction (skipping allowed): catch up now.
            this.openInteraction(next);
            return;
        }
        this.showUpNext(next, at - t, playing);
    }

    /**
     * Shows the "up next" countdown in the side panel.
     *
     * @param {object} next
     * @param {number} left seconds
     * @param {boolean} playing
     */
    showUpNext(next, left, playing) {
        const box = this.el.dynamic.querySelector('[data-region="upnext"]');
        if (!box) {
            return;
        }
        if (left > 8 || !playing) {
            box.hidden = true;
            return;
        }
        box.hidden = false;
        box.querySelector('[data-region="upnexttitle"]').textContent = `${S[next.type]} · ${next.title}`;
        const ring = box.querySelector('[data-region="upnextring"]');
        ring.style.strokeDashoffset = String(94.2 * (left / 8));
        const whole = Math.ceil(left);
        if (whole <= 3 && whole !== this.lastBeep) {
            this.lastBeep = whole;
            Sound.play('beep');
        }
    }

    /** Hides the up-next box. */
    hideUpNext() {
        const box = this.el.dynamic.querySelector('[data-region="upnext"]');
        if (box) {
            box.hidden = true;
        }
    }

    /**
     * Updates the time, playhead and fills.
     *
     * @param {number} t
     */
    drawProgress(t) {
        const d = this.duration || 1;
        const pct = (x) => `${Math.max(0, Math.min(100, (x / d) * 100))}%`;
        this.el.time.textContent = clock(t);
        this.el.played.style.width = pct(t);
        this.el.watched.style.width = pct(this.config.preventskip ? this.maxwatched : d);
        this.el.playhead.style.left = pct(t);
        this.el.timeline.setAttribute('aria-valuenow', String(Math.round(t)));
        this.el.timeline.setAttribute('aria-valuetext', `${clock(t)} / ${clock(d)}`);
    }

    /**
     * Draws the section segments and interaction markers on the timeline.
     */
    drawMarkers() {
        if (!this.sections || !this.duration) {
            return;
        }
        const d = this.duration;
        this.el.markers.replaceChildren();
        this.sections.forEach((s, i) => {
            if (i > 0) {
                const tick = document.createElement('span');
                tick.className = 'aiv-seg-tick';
                tick.style.left = `${(s.start / d) * 100}%`;
                this.el.markers.appendChild(tick);
            }
            const m = document.createElement('span');
            m.className = `aiv-marker is-${s.status}`;
            m.dataset.index = String(i);
            m.style.left = `${(this.pauseAt(s) / d) * 100}%`;
            m.title = `${i + 1}. ${s.title}`;
            this.el.markers.appendChild(m);
        });
    }

    /**
     * Timeline seeking and hover tips.
     */
    bindTimeline() {
        const tl = this.el.timeline;
        const at = (e) => {
            const r = tl.getBoundingClientRect();
            return Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)) * (this.duration || 0);
        };
        tl.addEventListener('click', (e) => {
            if (this.video && this.data && !this.open) {
                this.seekTo(at(e));
            }
        });
        tl.addEventListener('mousemove', (e) => {
            if (!this.sections || !this.duration) {
                return;
            }
            const t = at(e);
            const s = this.sections.slice().reverse().find((x) => x.start <= t) || this.sections[0];
            this.el.tip.hidden = false;
            this.el.tip.textContent = `${clock(t)} · ${s.title}`;
            this.el.tip.style.left = `${(t / this.duration) * 100}%`;
            this.el.tip.classList.toggle('is-locked', !!this.config.preventskip && t > this.maxwatched + 1);
        });
        tl.addEventListener('mouseleave', () => {
            this.el.tip.hidden = true;
        });
        tl.addEventListener('keydown', (e) => {
            if (!this.video || this.open) {
                return;
            }
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                e.preventDefault();
                e.stopPropagation();
                this.seekTo(this.video.time() + (e.key === 'ArrowRight' ? 5 : -5));
            }
        });
    }

    /**
     * Seeks, respecting "no skipping ahead".
     *
     * @param {number} t
     */
    seekTo(t) {
        if (!this.video) {
            return;
        }
        if (this.config.preventskip && t > this.maxwatched + 0.5) {
            this.toast(S.skipblocked, 'warn');
            Sound.play('back');
            t = this.maxwatched;
        }
        this.video.seek(Math.max(0, t));
    }

    /** Play / pause. */
    togglePlay() {
        if (!this.video || this.open) {
            return;
        }
        if (this.video.playing()) {
            this.video.pause();
        } else {
            this.video.play();
        }
    }

    /**
     * Cycles the playback speed.
     *
     * @param {HTMLElement} btn
     */
    cycleSpeed(btn) {
        const current = this.video ? this.video.rate : 1;
        const next = this.speeds[(this.speeds.indexOf(current) + 1) % this.speeds.length];
        if (this.video) {
            this.video.setRate(next);
        }
        btn.textContent = `${next}×`;
        Sound.play('select');
    }

    /**
     * Mutes the video sound.
     *
     * @param {HTMLElement} btn
     */
    toggleVideoMute(btn) {
        if (!this.video) {
            return;
        }
        const muted = !this.video.isMuted();
        this.video.setMuted(muted);
        btn.classList.toggle('is-off', muted);
    }

    /**
     * Jumps to a section from the list.
     *
     * @param {number} index
     */
    jumpToChapter(index) {
        const s = this.sections[index];
        if (!s || this.open) {
            return;
        }
        if (this.config.preventskip && s.start > this.maxwatched + 0.5) {
            this.toast(S.skipblocked, 'warn');
            Sound.play('back');
            return;
        }
        this.video.seek(s.start);
        this.video.play();
    }

    /* ---------------------------------------------------------------------------------------------------- */
    /* Side panel                                                                                           */
    /* ---------------------------------------------------------------------------------------------------- */

    /**
     * Renders the section list.
     */
    async showChapters() {
        this.el.panel.classList.remove('is-intro', 'is-interaction');
        await this.renderInto(this.el.dynamic, 'player_chapters', {
            sections: this.sections.map((s, i) => ({
                index: i,
                number: i + 1,
                title: s.title,
                time: clock(s.start),
                pause: clock(s.end),
                typename: S[s.type],
                typekey: s.type,
                status: s.status,
            })),
            count: this.sections.length,
        });
        this.el.home.hidden = true;
        this.el.dynamic.hidden = false;
        this.syncChapters();
    }

    /**
     * Updates statuses and the progress bar in the section list.
     */
    syncChapters() {
        const next = this.nextPending();
        const done = this.sections.filter((s) => s.status !== 'pending').length;
        this.el.dynamic.querySelectorAll('.aiv-chapter').forEach((li) => {
            const s = this.sections[parseInt(li.dataset.index, 10)];
            li.className = `aiv-chapter is-${s.status}`;
            if (s === next) {
                li.classList.add('is-current');
            }
            if (this.config.preventskip && s.start > this.maxwatched + 0.5 && s !== next) {
                li.classList.add('is-locked');
            }
        });
        const fill = this.el.dynamic.querySelector('[data-region="progressfill"]');
        if (fill) {
            fill.style.width = `${(done / Math.max(1, this.sections.length)) * 100}%`;
        }
        const text = this.el.dynamic.querySelector('[data-region="progresstext"]');
        if (text) {
            text.textContent = fmt(S.progressof, {done, total: this.sections.length});
        }
        this.drawMarkers();
    }

    /**
     * Updates the score pill.
     *
     * @param {number} score
     * @param {boolean} animate
     */
    updateScore(score, animate = true) {
        this.score = score;
        this.el.scorevalue.textContent = String(score);
        this.el.scoretotal.textContent = `/${this.sections.length}`;
        if (animate) {
            Fx.pop(this.el.score, 1.25);
        }
    }

    /* ---------------------------------------------------------------------------------------------------- */
    /* Interactions                                                                                         */
    /* ---------------------------------------------------------------------------------------------------- */

    /**
     * Pauses the video and shows the section's interaction.
     *
     * @param {object} section
     */
    async openInteraction(section) {
        if (this.open) {
            return;
        }
        this.open = section;
        this.video.pause();
        this.hideUpNext();
        Sound.play('pop');
        this.showOverlay('turn');
        this.el.panel.classList.add('is-interaction');
        this.el.shell.classList.add('is-interacting');
        const tries = this.mode === 'test' && this.config.maxtries ? this.config.maxtries - section.tries : 0;
        let triesText = '';
        if (tries === 1) {
            triesText = S.lasttry;
        } else if (tries > 1) {
            triesText = fmt(S.triesleft, tries);
        }
        await this.renderInto(this.el.dynamic, 'player_interaction', {
            sectionid: section.id,
            typekey: section.type,
            typename: S[section.type],
            ic: {[Interactions.ICONS[section.type]]: true},
            counter: fmt(S.interactionof, {current: section.index + 1, total: this.sections.length}),
            title: section.title,
            prompt: section.prompt,
            how: S[`${section.type}_how`],
            learn: this.mode === 'learn',
            hashint: section.hashint,
            triesleft: triesText,
            selectcount: section.selectcount ? fmt(S.selectedof, {count: 0, total: section.selectcount}) : '',
        });
        const card = this.el.dynamic.querySelector('[data-region="card"]');
        this.card = card;
        const checkBtn = card.querySelector('[data-action="check"]');
        this.controller = await Interactions.mount(card.querySelector('[data-region="body"]'), section, {
            S,
            fmt,
            card,
            onChange: (complete) => {
                checkBtn.disabled = !complete || this.checking;
                checkBtn.classList.toggle('is-ready', complete);
            },
        });
        this.shownAt = Date.now();
        card.classList.add('is-in');
        const prompt = card.querySelector('.aiv-card-prompt');
        if (prompt) {
            prompt.setAttribute('tabindex', '-1');
            prompt.focus({preventScroll: true});
        }
        this.say(`${S.yourturn} ${section.prompt}`);
        if (window.matchMedia('(max-width: 900px)').matches) {
            card.scrollIntoView({behavior: Fx.REDUCED ? 'auto' : 'smooth', block: 'start'});
        }
    }

    /**
     * Shows a banner over the video.
     *
     * @param {string} kind turn|rewind|none
     * @param {string} text banner text for "rewind"
     */
    showOverlay(kind, text = '') {
        const o = this.el.overlay;
        if (kind === 'none') {
            o.hidden = true;
            o.className = 'aiv-overlay';
            return;
        }
        o.className = `aiv-overlay is-${kind}`;
        o.replaceChildren();
        const badge = document.createElement('div');
        badge.className = 'aiv-overlay-badge';
        badge.textContent = kind === 'turn' ? S.yourturn : text;
        o.appendChild(badge);
        if (kind === 'turn') {
            const arrow = document.createElement('span');
            arrow.className = 'aiv-overlay-arrow';
            arrow.setAttribute('aria-hidden', 'true');
            o.appendChild(arrow);
        }
        o.hidden = false;
    }

    /**
     * Sends the answer for marking.
     */
    async check() {
        const section = this.open;
        if (!section || this.checking || !this.controller) {
            return;
        }
        this.checking = true;
        const btn = this.card.querySelector('[data-action="check"]');
        btn.disabled = true;
        btn.classList.add('is-busy');
        const timespent = Math.round((Date.now() - this.shownAt) / 1000);
        let res;
        try {
            res = await call('submit_response', {
                attemptid: this.data.attemptid,
                sectionid: section.id,
                response: this.controller.response(),
                timespent,
            });
        } catch (err) {
            this.checking = false;
            btn.disabled = false;
            btn.classList.remove('is-busy');
            Notification.exception(err);
            return;
        }
        this.checking = false;
        btn.classList.remove('is-busy');
        section.tries = res.tries;
        section.status = res.status;
        section.points = res.points;
        this.controller.showResults(res.results);
        if (res.correct) {
            this.onCorrect(section, res);
        } else {
            this.onWrong(section, res);
        }
    }

    /**
     * Correct answer.
     *
     * @param {object} section
     * @param {object} res
     */
    async onCorrect(section, res) {
        this.controller.lock();
        Sound.play('correct');
        this.card.classList.add('is-solved');
        const praise = [S.praise1, S.praise2, S.praise3, S.praise4][Math.floor(Math.random() * 4)];
        await this.showFeedback({
            correct: true,
            headline: praise,
            haspoints: res.points > 0,
            points: fmt(S.pointsearned, res.points),
            feedback: res.feedback,
            continuelabel: this.nextPending() ? S.continuevideo : S.finish,
        });
        if (res.points > 0) {
            window.setTimeout(() => {
                Sound.play('coin');
                Fx.floatPoints(this.card.querySelector('.aiv-feedback-points'), this.el.score, `+${res.points}`);
                this.updateScore(res.score);
            }, 350);
        } else {
            this.updateScore(res.score, false);
        }
        Fx.confetti(this.card, 70, 0.55);
        this.say(`${praise} ${res.feedback}`);
        this.autoContinue(5);
    }

    /**
     * Wrong answer: explain, then replay the section.
     *
     * @param {object} section
     * @param {object} res
     */
    async onWrong(section, res) {
        Sound.play('wrong');
        Fx.shake(this.card);
        if (res.status === 'skipped') {
            this.controller.lock();
            await this.showFeedback({
                skipped: true,
                headline: S.skipped,
                feedback: res.feedback,
                continuelabel: this.nextPending() ? S.continuevideo : S.finish,
            });
            this.say(`${S.skipped} ${res.feedback}`);
            return;
        }
        this.controller.lock();
        let headline = this.mode === 'learn' ? S.wrong_learn : S.wrong_test;
        if (res.missing > 0) {
            headline += ` · ${fmt(S.missing, res.missing)}`;
        }
        const extra = res.triesleft > 0 ? ` ${res.triesleft === 1 ? S.lasttry : fmt(S.triesleft, res.triesleft)}` : '';
        await this.showFeedback({
            wrong: true,
            headline,
            feedback: `${res.feedback}${extra}`.trim(),
            countdown: fmt(S.countdown_rewatch, {time: clock(section.start), secs: 6}),
            canreveal: this.mode === 'learn',
        });
        this.say(`${headline}. ${res.feedback}`);
        this.countdown(6, (left) => {
            const el = this.card && this.card.querySelector('[data-region="countdown"]');
            if (el) {
                el.textContent = fmt(S.countdown_rewatch, {time: clock(section.start), secs: left});
            }
        }, () => this.rewatch());
    }

    /**
     * Renders the feedback panel in the card.
     *
     * @param {object} context
     */
    async showFeedback(context) {
        const host = this.card.querySelector('[data-region="feedback"]');
        this.card.querySelector('[data-region="foot"]').hidden = true;
        await this.renderInto(host, 'player_feedback', Object.assign({correct: false, wrong: false, skipped: false},
            context));
        this.card.classList.add('has-feedback');
        const primary = host.querySelector('.aiv-btn-primary');
        if (primary) {
            primary.focus({preventScroll: true});
        }
        if (window.matchMedia('(max-width: 900px)').matches) {
            host.scrollIntoView({behavior: Fx.REDUCED ? 'auto' : 'smooth', block: 'nearest'});
        }
    }

    /**
     * Runs a visible countdown.
     *
     * @param {number} secs
     * @param {Function} onTick
     * @param {Function} onDone
     */
    countdown(secs, onTick, onDone) {
        window.clearInterval(this.countHandle);
        let left = secs;
        this.countHandle = window.setInterval(() => {
            left--;
            if (!this.open) {
                window.clearInterval(this.countHandle);
                return;
            }
            onTick(left);
            if (left <= 0) {
                window.clearInterval(this.countHandle);
                onDone();
            }
        }, 1000);
    }

    /**
     * Continues automatically after a correct answer.
     *
     * @param {number} secs
     */
    autoContinue(secs) {
        const btn = this.card.querySelector('[data-action="continue"]');
        if (btn) {
            btn.classList.add('is-counting');
            btn.style.setProperty('--count', `${secs}s`);
        }
        this.countdown(secs, () => null, () => this.continueVideo());
    }

    /**
     * Uses a hint (Learn mode).
     *
     * @param {HTMLElement} btn
     */
    async hint(btn) {
        const section = this.open;
        if (!section) {
            return;
        }
        btn.disabled = true;
        try {
            const res = await call('get_hint', {attemptid: this.data.attemptid, sectionid: section.id});
            Sound.play('hint');
            const box = this.card.querySelector('[data-region="hintbox"]');
            const text = [res.text, S.hintnopoint].filter((x) => x).join(' ');
            box.querySelector('[data-region="hinttext"]').textContent = text;
            box.hidden = false;
            box.classList.remove('is-in');
            void box.offsetWidth;
            box.classList.add('is-in');
            this.card.classList.add('hint-used');
            this.controller.assist(res.assist);
            btn.disabled = res.hintsleft <= 0;
            this.say(text);
        } catch (err) {
            btn.disabled = false;
            Notification.exception(err);
        }
    }

    /**
     * Shows the answer (Learn mode).
     */
    async reveal() {
        const section = this.open;
        if (!section) {
            return;
        }
        window.clearInterval(this.countHandle);
        try {
            const res = await call('reveal_answer', {attemptid: this.data.attemptid, sectionid: section.id});
            this.controller.solve(res.solution);
            Sound.play('card');
            section.status = 'revealed';
            section.points = 0;
            this.updateScore(res.score, false);
            this.card.classList.add('is-revealed');
            await this.showFeedback({
                skipped: true,
                headline: S.answerrevealed,
                feedback: res.feedback,
                continuelabel: this.nextPending() ? S.continuevideo : S.finish,
            });
            this.say(`${S.answerrevealed} ${res.feedback}`);
        } catch (err) {
            Notification.exception(err);
        }
    }

    /**
     * Closes the interaction card and shows the section list again.
     */
    async closeInteraction() {
        window.clearInterval(this.countHandle);
        this.open = null;
        this.controller = null;
        this.card = null;
        this.el.shell.classList.remove('is-interacting');
        this.showOverlay('none');
        await this.showChapters();
    }

    /**
     * Continues the video after an interaction.
     */
    async continueVideo() {
        const section = this.open;
        if (!section) {
            return;
        }
        await this.closeInteraction();
        const next = this.nextPending();
        const atEnd = this.duration && this.pauseAt(section) >= this.duration - 1.2;
        if (!next && atEnd) {
            this.finish();
            return;
        }
        Sound.play('whoosh');
        this.video.play();
    }

    /**
     * Replays the current section from its start.
     */
    async rewatch() {
        const section = this.open;
        if (!section) {
            return;
        }
        call('record_rewatch', {attemptid: this.data.attemptid, sectionid: section.id}).catch(() => null);
        await this.closeInteraction();
        Sound.play('rewind');
        this.maxwatched = Math.min(this.maxwatched, section.start);
        this.showOverlay('rewind', fmt(S.rewatching, clock(section.start)));
        window.setTimeout(() => {
            if (!this.open) {
                this.showOverlay('none');
            }
        }, 1800);
        this.video.seek(section.start);
        this.video.play();
        this.syncChapters();
    }

    /* ---------------------------------------------------------------------------------------------------- */
    /* Results                                                                                              */
    /* ---------------------------------------------------------------------------------------------------- */

    /**
     * Finishes the attempt and shows the results.
     */
    async finish() {
        if (this.finished || this.finishing) {
            return;
        }
        this.finishing = true;
        if (this.video) {
            this.video.pause();
        }
        window.clearInterval(this.tickHandle);
        window.clearInterval(this.secondHandle);
        let summary;
        try {
            summary = await call('finish_attempt', {attemptid: this.data.attemptid, elapsed: this.unsaved});
        } catch (err) {
            this.finishing = false;
            Notification.exception(err);
            return;
        }
        this.finished = true;
        this.unsaved = 0;
        await this.showResults(summary);
    }

    /**
     * Renders the results screen.
     *
     * @param {object} summary
     */
    async showResults(summary) {
        const pct = summary.percent;
        const pass = this.config.passpoints && summary.graded ? this.config.passpoints : 0;
        const passed = pass ? summary.score >= pass : pct >= 60;
        let headline;
        if (pct >= 90) {
            headline = S.excellent;
        } else if (pct >= 70) {
            headline = S.greatjob;
        } else {
            headline = pct >= 50 ? S.goodeffort : S.keeppractising;
        }
        const statusText = (s) => {
            if (s.status === 'solved') {
                if (s.hints > 0) {
                    return S.status_hinted;
                }
                return s.tries <= 1 ? S.status_firsttry : S.status_solved;
            }
            return S[`status_${s.status === 'pending' ? 'notanswered' : s.status}`];
        };
        const context = {
            mode: summary.mode,
            modename: summary.mode === 'learn' ? S.learnmode : S.testmode,
            headline,
            sub: fmt(S.correctof, {score: summary.score, max: summary.maxscore}),
            percent: pct,
            pass: passed,
            passtext: pass ? fmt(passed ? S.passmark_met : S.passmark_notmet, pass) : '',
            duration: clock(summary.duration),
            firsttry: summary.firsttry,
            hints: summary.hints,
            rewatches: summary.rewatches,
            learn: summary.mode === 'learn',
            attemptsleft: summary.graded && summary.attemptsleft >= 0 ? String(summary.attemptsleft) : '',
            canretry: !summary.graded || summary.attemptsleft !== 0,
            sections: summary.sections.map((s, i) => ({
                number: i + 1,
                title: s.title,
                typename: S[s.type],
                typekey: s.type,
                solved: s.status === 'solved',
                statustext: statusText(s),
                points: s.points,
                tries: s.tries,
                time: clock(s.timespent),
            })),
            hasleaderboard: summary.leaderboard.length > 0,
            leaderboard: summary.leaderboard.map((r) => Object.assign({}, r, {time: clock(r.duration)})),
        };
        await this.renderInto(this.el.results, 'player_results', context);
        this.el.stage.hidden = true;
        this.el.results.hidden = false;
        this.el.shell.classList.add('is-results');
        const ring = this.el.results.querySelector('[data-region="ringfg"]');
        const circumference = 2 * Math.PI * 52;
        ring.style.strokeDasharray = String(circumference);
        ring.style.strokeDashoffset = String(circumference);
        requestAnimationFrame(() => {
            ring.style.transition = Fx.REDUCED ? 'none' : `stroke-dashoffset 1500ms ${Fx.EASE}`;
            ring.style.strokeDashoffset = String(circumference * (1 - pct / 100));
        });
        Fx.countUp(this.el.results.querySelector('[data-region="count"]'), pct, 1500);
        if (passed) {
            Sound.play('finish');
            window.setTimeout(() => Fx.confetti(this.el.shell, 200), 400);
            window.setTimeout(() => Fx.confetti(this.el.shell, 120), 1300);
        } else {
            Sound.play('fail');
        }
        this.say(`${headline} ${context.sub}`);
        this.el.shell.focus({preventScroll: true});
        this.el.shell.scrollIntoView({behavior: Fx.REDUCED ? 'auto' : 'smooth', block: 'start'});
    }

    /**
     * Starts a new attempt in the same mode.
     */
    async retry() {
        this.finished = false;
        this.finishing = false;
        this.data = null;
        this.config.resume = {};
        this.el.results.hidden = true;
        this.el.stage.hidden = false;
        this.el.shell.classList.remove('is-results');
        await this.showIntro(this.mode);
    }

    /* ---------------------------------------------------------------------------------------------------- */
    /* Chrome                                                                                               */
    /* ---------------------------------------------------------------------------------------------------- */

    /**
     * Leaves the player (progress is saved, the attempt can be resumed).
     */
    exit() {
        this.saveProgress();
        if (this.video) {
            this.video.pause();
        }
        window.setTimeout(() => this.reload(), 250);
    }

    /** Reloads the page (fresh attempt counts and resume state). */
    reload() {
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => null);
        }
        window.location.reload();
    }

    /** Syncs the mute button. */
    syncMute() {
        const muted = Sound.isMuted();
        const btn = this.root.querySelector('[data-action="mute"]');
        if (!btn) {
            return;
        }
        btn.classList.toggle('is-off', muted);
        btn.hidden = !this.config.sounds;
        btn.setAttribute('aria-pressed', muted ? 'true' : 'false');
        btn.setAttribute('aria-label', muted ? (S.unmute || '') : (S.mute || ''));
        btn.title = btn.getAttribute('aria-label');
    }

    /**
     * Full screen for the whole card (native, with a CSS fallback for iPhone).
     */
    toggleFullscreen() {
        const shell = this.el.shell;
        const fsEl = document.fullscreenElement || document.webkitFullscreenElement;
        if (fsEl || shell.classList.contains('is-pseudo-full')) {
            if (fsEl) {
                (document.exitFullscreen || document.webkitExitFullscreen).call(document);
            }
            shell.classList.remove('is-pseudo-full');
            document.body.classList.remove('aiv-lock-scroll');
            this.syncFullscreen();
            return;
        }
        const req = shell.requestFullscreen || shell.webkitRequestFullscreen;
        if (req) {
            Promise.resolve(req.call(shell)).catch(() => this.pseudoFull());
        } else {
            this.pseudoFull();
        }
    }

    /** CSS full screen fallback. */
    pseudoFull() {
        this.el.shell.classList.add('is-pseudo-full');
        document.body.classList.add('aiv-lock-scroll');
        this.syncFullscreen();
    }

    /**
     * Scales the whole card to the screen in full screen, like a presentation: the layout is designed for a
     * 1400 x 880 stage, and text, activities and video grow or shrink together to fill any screen.
     * Phones keep their own stacked layout at normal size.
     */
    fitFullscreen() {
        const shell = this.el.shell;
        const w = window.innerWidth;
        const h = window.innerHeight;
        const staged = shell.classList.contains('is-full') && w >= 900 && h >= 560;
        const scale = staged ? Math.max(0.8, Math.min(2.5, Math.min(w / 1400, h / 880))) : 1;
        shell.style.setProperty('--aiv-s', String(scale));
        shell.classList.toggle('is-staged', staged);
        this.stageScale = scale;
        this.fitContent();
    }

    /**
     * Refits the full screen content whenever it changes (new activity, feedback, hints, results, resizing).
     */
    watchFit() {
        const boxes = [this.el.panel, this.root.querySelector('[data-region="inside"]'), this.el.results].filter(Boolean);
        const later = () => {
            if (this.fitQueued || !this.el.shell.classList.contains('is-staged')) {
                return;
            }
            this.fitQueued = true;
            window.requestAnimationFrame(() => {
                this.fitQueued = false;
                this.fitContent();
            });
        };
        if (window.MutationObserver) {
            // Matching lines are redrawn after every fit, so changes inside drawings are ignored.
            const drawing = (r) => {
                const el = r.target.nodeType === 1 ? r.target : r.target.parentElement;
                return !!el && !!el.closest('svg');
            };
            const mo = new MutationObserver((records) => {
                if (records.some((r) => !drawing(r))) {
                    later();
                }
            });
            boxes.forEach((box) => mo.observe(box, {
                childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class', 'hidden'],
            }));
        }
        if (window.ResizeObserver) {
            const ro = new ResizeObserver(later);
            boxes.forEach((box) => ro.observe(box));
        }
        // Web fonts and images can change heights after the first fit.
        window.addEventListener('load', later);
    }

    /**
     * Full screen shows everything at once, with no scroll bars: any box whose content is taller than the box is
     * shrunk (CSS zoom on its content) to the largest size that fits. Out of full screen everything is reset.
     */
    fitContent() {
        const shell = this.el.shell;
        const staged = shell.classList.contains('is-staged');
        const inside = this.root.querySelector('[data-region="inside"]');
        const boxes = [this.el.panel, inside, this.el.results].filter(Boolean);
        let panelfit = 1;
        // Home screen: the interaction list gets the height it needs (up to 48% of the column) and the video the rest.
        if (inside) {
            const left = inside.parentElement;
            left.style.removeProperty('--aiv-below');
            if (staged && !shell.classList.contains('is-playing-mode') && !inside.hidden) {
                [...inside.children].forEach((kid) => {
                    kid.style.zoom = '';
                });
                inside.style.flex = 'none';
                inside.style.height = 'auto';
                const need = inside.offsetHeight + (parseFloat(window.getComputedStyle(left).rowGap) || 0);
                inside.style.flex = '';
                inside.style.height = '';
                left.style.setProperty('--aiv-below', `${Math.ceil(Math.min(need, left.clientHeight * 0.48))}px`);
            }
        }
        boxes.forEach((box) => {
            const kids = [...box.children];
            const apply = (z) => kids.forEach((kid) => {
                kid.style.zoom = z === 1 ? '' : String(z);
            });
            // Compare the laid-out content with the box on screen; scrollHeight is not used because confetti, points
            // and sliding feedback are drawn outside the content for a moment and are not content.
            const fits = () => {
                const top = box.getBoundingClientRect().top;
                const bottom = Math.max(top, ...kids.filter((kid) => !kid.hidden).map((kid) => kid.getBoundingClientRect().bottom));
                return bottom - top <= box.getBoundingClientRect().height + 1;
            };
            apply(1);
            let best = 1;
            if (staged && !box.hidden && box.clientHeight > 0 && !fits()) {
                // Largest zoom that fits (text reflows as it gets narrower, so search rather than divide).
                let lo = 0.4;
                let hi = 1;
                for (let i = 0; i < 8; i++) {
                    const mid = (lo + hi) / 2;
                    apply(mid);
                    if (fits()) {
                        lo = mid;
                    } else {
                        hi = mid;
                    }
                }
                best = Math.floor(lo * 1000) / 1000;
                apply(best);
            }
            if (box === this.el.panel) {
                panelfit = best;
            }
        });
        // Drag and animation distances inside the activity are converted with the total scale.
        Fx.setScale((this.stageScale || 1) * panelfit);
        if (panelfit !== this.panelFit && this.controller && this.controller.draw) {
            this.controller.draw();
        }
        this.panelFit = panelfit;
    }

    /** Updates the full screen button. */
    syncFullscreen() {
        const shell = this.el.shell;
        const on = !!(document.fullscreenElement || document.webkitFullscreenElement) ||
            shell.classList.contains('is-pseudo-full');
        shell.classList.toggle('is-full', on);
        this.fitFullscreen();
        const btn = this.root.querySelector('[data-action="fullscreen"]');
        btn.classList.toggle('is-off', on);
        btn.setAttribute('aria-label', on ? S.exitfullscreen : S.fullscreen);
        btn.title = btn.getAttribute('aria-label');
        window.setTimeout(() => {
            if (this.controller && this.controller.draw) {
                this.controller.draw();
            }
        }, 150);
    }

    /* ---------------------------------------------------------------------------------------------------- */
    /* Teacher: AI build on first view                                                                      */
    /* ---------------------------------------------------------------------------------------------------- */

    /**
     * Runs the pending AI generation, then reloads.
     */
    async buildWithAi() {
        const steps = this.root.querySelectorAll('.aiv-building-steps li');
        let i = 0;
        const stepper = window.setInterval(() => {
            steps.forEach((li, k) => {
                li.classList.toggle('is-done', k < i);
                li.classList.toggle('is-active', k === i);
            });
            i = Math.min(i + 1, steps.length - 1);
        }, 2500);
        try {
            await call('generate', {cmid: this.config.cmid, action: 'continue'});
            steps.forEach((li) => li.classList.add('is-done'));
            Sound.play('finish');
        } catch (err) {
            this.toast(S.buildfailed, 'warn');
        }
        window.clearInterval(stepper);
        window.setTimeout(() => window.location.reload(), 900);
    }
}

/**
 * Entry point.
 *
 * @param {string} selector
 */
export const init = async(selector) => {
    const root = document.querySelector(selector);
    if (!root || root.dataset.aivInit) {
        return;
    }
    root.dataset.aivInit = '1';
    try {
        await loadStrings();
        const config = JSON.parse(root.dataset.config || '{}');
        new Player(root, config);
    } catch (err) {
        Notification.exception(err);
    }
};
