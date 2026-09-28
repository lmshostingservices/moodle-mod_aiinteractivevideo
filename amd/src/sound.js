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
 * Synthesised interface sounds (Web Audio API): no audio files, no licensing, tiny footprint.
 *
 * @module     mod_aiinteractivevideo/sound
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const STORAGE_KEY = 'mod_aiinteractivevideo_muted';

let ctx = null;
let master = null;
let noiseBuffer = null;
let muted = false;
let allowed = true;
let lastZone = 0;

try {
    muted = window.localStorage.getItem(STORAGE_KEY) === '1';
} catch (e) {
    muted = false;
}

/**
 * Lazily creates the audio context (must follow a user gesture on most browsers).
 *
 * @returns {AudioContext|null}
 */
const audio = () => {
    if (!allowed || muted) {
        return null;
    }
    if (!ctx) {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) {
            return null;
        }
        ctx = new AC();
        master = ctx.createGain();
        master.gain.value = 0.55;
        const comp = ctx.createDynamicsCompressor();
        comp.threshold.value = -18;
        comp.ratio.value = 6;
        master.connect(comp);
        comp.connect(ctx.destination);
        noiseBuffer = ctx.createBuffer(1, ctx.sampleRate * 0.6, ctx.sampleRate);
        const data = noiseBuffer.getChannelData(0);
        for (let i = 0; i < data.length; i++) {
            data[i] = Math.random() * 2 - 1;
        }
    }
    if (ctx.state === 'suspended') {
        ctx.resume();
    }
    return ctx;
};

/**
 * Plays an enveloped oscillator note.
 *
 * @param {number} freq start frequency
 * @param {number} at seconds offset from now
 * @param {number} dur duration in seconds
 * @param {object} opts options: type, vol, to (glide target), attack
 */
const tone = (freq, at, dur, opts = {}) => {
    const ac = audio();
    if (!ac) {
        return;
    }
    const t = ac.currentTime + at;
    const osc = ac.createOscillator();
    const gain = ac.createGain();
    osc.type = opts.type || 'sine';
    osc.frequency.setValueAtTime(freq, t);
    if (opts.to) {
        osc.frequency.exponentialRampToValueAtTime(opts.to, t + dur);
    }
    const vol = opts.vol ?? 0.25;
    gain.gain.setValueAtTime(0.0001, t);
    gain.gain.exponentialRampToValueAtTime(vol, t + (opts.attack ?? 0.006));
    gain.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    osc.connect(gain);
    gain.connect(master);
    osc.start(t);
    osc.stop(t + dur + 0.02);
};

/**
 * Plays a filtered noise burst (swishes, thuds).
 *
 * @param {number} at offset
 * @param {number} dur duration
 * @param {object} opts options: freq, to, q, vol, type
 */
const noise = (at, dur, opts = {}) => {
    const ac = audio();
    if (!ac) {
        return;
    }
    const t = ac.currentTime + at;
    const src = ac.createBufferSource();
    src.buffer = noiseBuffer;
    const filter = ac.createBiquadFilter();
    filter.type = opts.type || 'bandpass';
    filter.frequency.setValueAtTime(opts.freq || 1200, t);
    if (opts.to) {
        filter.frequency.exponentialRampToValueAtTime(opts.to, t + dur);
    }
    filter.Q.value = opts.q ?? 1.2;
    const gain = ac.createGain();
    gain.gain.setValueAtTime(0.0001, t);
    gain.gain.exponentialRampToValueAtTime(opts.vol ?? 0.2, t + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    src.connect(filter);
    filter.connect(gain);
    gain.connect(master);
    src.start(t);
    src.stop(t + dur + 0.02);
};

const SOUNDS = {
    // Label lifted: soft rising "pluck" with a tiny air swish.
    pickup: () => {
        tone(520, 0, 0.09, {type: 'triangle', to: 880, vol: 0.18});
        noise(0, 0.08, {freq: 2500, to: 5000, vol: 0.05});
    },
    // Dragging over a drop zone: crisp tick (rate limited).
    zone: () => {
        const now = Date.now();
        if (now - lastZone < 60) {
            return;
        }
        lastZone = now;
        tone(1760, 0, 0.035, {type: 'sine', vol: 0.09});
        tone(2640, 0.012, 0.03, {type: 'sine', vol: 0.04});
    },
    // Label set down: woody "thock".
    drop: () => {
        tone(220, 0, 0.12, {type: 'sine', to: 110, vol: 0.32});
        noise(0, 0.05, {freq: 900, q: 0.8, vol: 0.12});
    },
    // Correct: two-note bell.
    correct: () => {
        tone(784, 0, 0.22, {type: 'triangle', vol: 0.2});
        tone(1175, 0.08, 0.32, {type: 'sine', vol: 0.22});
        tone(2350, 0.08, 0.18, {type: 'sine', vol: 0.04});
    },
    // Incorrect: muted low "bonk".
    wrong: () => {
        tone(196, 0, 0.16, {type: 'square', to: 147, vol: 0.07});
        tone(147, 0.07, 0.2, {type: 'triangle', to: 110, vol: 0.14});
    },
    // Label springs back to the tray.
    back: () => {
        tone(700, 0, 0.12, {type: 'triangle', to: 350, vol: 0.1});
        noise(0, 0.1, {freq: 3000, to: 900, vol: 0.04});
    },
    // Select via tap / keyboard.
    select: () => tone(988, 0, 0.06, {type: 'sine', vol: 0.12}),
    // Purpose card appears.
    card: () => {
        tone(660, 0, 0.1, {type: 'sine', to: 990, vol: 0.1});
        noise(0.02, 0.12, {freq: 1800, to: 4200, vol: 0.03});
    },
    // Slide transition swoosh.
    whoosh: () => noise(0, 0.35, {freq: 400, to: 3200, q: 0.7, vol: 0.09}),
    // Hint shimmer.
    hint: () => [1319, 1568, 2093].forEach((f, i) => tone(f, i * 0.05, 0.18, {vol: 0.07})),
    // Slide complete: bright arpeggio.
    slide: () => [523, 659, 784, 1047].forEach((f, i) => tone(f, i * 0.075, 0.3, {type: 'triangle', vol: 0.16})),
    // Activity complete: fanfare.
    finish: () => {
        [523, 659, 784, 1047, 1319].forEach((f, i) => tone(f, i * 0.09, 0.35, {type: 'triangle', vol: 0.16}));
        [523, 659, 784].forEach((f) => tone(f, 0.5, 0.9, {type: 'sine', vol: 0.1}));
        tone(1047, 0.5, 1.0, {type: 'sine', vol: 0.12});
    },
    // Not passed: gentle, encouraging descending motif.
    fail: () => {
        [659, 587, 523].forEach((f, i) => tone(f, i * 0.16, 0.34, {type: 'triangle', vol: 0.13}));
        tone(392, 0.5, 0.6, {type: 'sine', vol: 0.12});
    },
    // Interaction appears: bright rising "pop" with sparkle.
    pop: () => {
        tone(440, 0, 0.12, {type: 'triangle', to: 880, vol: 0.16});
        [1319, 1760].forEach((f, i) => tone(f, 0.06 + i * 0.05, 0.14, {vol: 0.06}));
    },
    // Countdown before an interaction.
    beep: () => tone(988, 0, 0.07, {type: 'sine', vol: 0.08}),
    // Rewind for a replay: descending swoosh.
    rewind: () => {
        noise(0, 0.4, {freq: 3200, to: 300, q: 0.8, vol: 0.08});
        tone(880, 0, 0.35, {type: 'triangle', to: 220, vol: 0.07});
    },
    // Card flip / swipe away.
    flip: () => noise(0, 0.14, {freq: 1600, to: 3800, q: 1.4, vol: 0.06}),
    swipe: () => noise(0, 0.22, {freq: 900, to: 2600, q: 0.9, vol: 0.08}),
    // Point earned: coin-like double ding.
    coin: () => {
        tone(1319, 0, 0.09, {type: 'square', vol: 0.05});
        tone(1760, 0.08, 0.25, {type: 'square', vol: 0.05});
        tone(2637, 0.08, 0.2, {type: 'sine', vol: 0.04});
    },
    // Timer tick (last seconds).
    tick: () => tone(1200, 0, 0.03, {type: 'square', vol: 0.03}),
};

/**
 * Plays a named sound.
 *
 * @param {string} name
 */
export const play = (name) => {
    if (muted || !allowed || !SOUNDS[name]) {
        return;
    }
    try {
        SOUNDS[name]();
    } catch (e) {
        // Audio is progressive enhancement only.
    }
};

/**
 * Enables/disables sounds for this activity (teacher setting).
 *
 * @param {boolean} on
 */
export const setAllowed = (on) => {
    allowed = !!on;
};

/**
 * Whether the user muted sounds.
 *
 * @returns {boolean}
 */
export const isMuted = () => muted;

/**
 * Toggles the user's mute preference.
 *
 * @returns {boolean} new muted state
 */
export const toggleMute = () => {
    muted = !muted;
    try {
        window.localStorage.setItem(STORAGE_KEY, muted ? '1' : '0');
    } catch (e) {
        // Storage unavailable; preference lasts for this page only.
    }
    if (!muted) {
        play('select');
    }
    return muted;
};
