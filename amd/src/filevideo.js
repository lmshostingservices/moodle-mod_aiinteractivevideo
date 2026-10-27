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
 * Uploaded MP4 video played with the browser's own video element. Same interface as mod_aiinteractivevideo/youtube.
 *
 * @module     mod_aiinteractivevideo/filevideo
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * An uploaded video bound to a container.
 */
export class Video {
    /**
     * Creates the player.
     *
     * @param {HTMLElement} container
     * @param {string} url address of the uploaded file
     * @param {object} handlers onReady, onState(state: playing|paused|ended|buffering), onError
     */
    constructor(container, url, handlers = {}) {
        this.handlers = handlers;
        this.ready = false;
        this.rate = 1;
        const el = document.createElement('video');
        el.className = 'aiv-filevideo';
        el.preload = 'metadata';
        el.playsInline = true;
        el.setAttribute('playsinline', '');
        el.setAttribute('controlslist', 'nodownload noplaybackrate');
        el.disablePictureInPicture = true;
        el.addEventListener('contextmenu', (e) => e.preventDefault());
        this.el = el;
        const state = (name) => () => this.handlers.onState && this.handlers.onState(name);
        el.addEventListener('playing', state('playing'));
        el.addEventListener('pause', () => {
            // The browser fires pause just before ended; report ended only.
            if (!el.ended && this.handlers.onState) {
                this.handlers.onState('paused');
            }
        });
        el.addEventListener('ended', state('ended'));
        el.addEventListener('waiting', state('buffering'));
        this.promise = new Promise((resolve) => {
            const ready = () => {
                if (this.ready) {
                    return;
                }
                this.ready = true;
                el.playbackRate = this.rate;
                if (this.handlers.onReady) {
                    this.handlers.onReady();
                }
                resolve(this);
            };
            el.addEventListener('loadedmetadata', ready);
            // A file that cannot be played is reported once, as a toast, like a YouTube player error.
            el.addEventListener('error', () => this.handlers.onError && this.handlers.onError(el.error ? el.error.code : 0));
        });
        el.src = url;
        container.appendChild(el);
    }

    /** Plays. */
    play() {
        if (this.ready) {
            const result = this.el.play();
            if (result && result.catch) {
                // Autoplay rules can refuse play() until the learner interacts; the play button still works.
                result.catch(() => this.handlers.onState && this.handlers.onState('paused'));
            }
        }
    }

    /** Pauses. */
    pause() {
        if (this.ready) {
            this.el.pause();
        }
    }

    /**
     * Seeks.
     *
     * @param {number} seconds
     */
    seek(seconds) {
        if (this.ready) {
            const end = this.duration();
            this.el.currentTime = Math.max(0, end ? Math.min(seconds, end) : seconds);
        }
    }

    /**
     * Current time.
     *
     * @returns {number}
     */
    time() {
        return this.ready ? (this.el.currentTime || 0) : 0;
    }

    /**
     * Duration.
     *
     * @returns {number}
     */
    duration() {
        return this.ready && isFinite(this.el.duration) ? this.el.duration : 0;
    }

    /**
     * Whether it is playing.
     *
     * @returns {boolean}
     */
    playing() {
        return this.ready && !this.el.paused && !this.el.ended;
    }

    /**
     * Sets the playback rate.
     *
     * @param {number} rate
     */
    setRate(rate) {
        this.rate = rate;
        if (this.ready) {
            this.el.playbackRate = rate;
        }
    }

    /**
     * Mutes or unmutes the video sound.
     *
     * @param {boolean} on
     */
    setMuted(on) {
        if (this.ready) {
            this.el.muted = !!on;
        }
    }

    /**
     * Whether the video sound is muted.
     *
     * @returns {boolean}
     */
    isMuted() {
        return this.ready ? this.el.muted : false;
    }

    /** Removes the player. */
    destroy() {
        this.el.pause();
        this.el.removeAttribute('src');
        this.el.load();
        this.el.remove();
    }
}
