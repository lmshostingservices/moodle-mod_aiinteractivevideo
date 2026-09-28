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
 * Thin wrapper around the YouTube IFrame Player API (privacy-enhanced youtube-nocookie.com host).
 *
 * @module     mod_aiinteractivevideo/youtube
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const API_URL = 'https://www.youtube.com/iframe_api';
let apiPromise = null;

/**
 * Loads the IFrame API once.
 *
 * @returns {Promise<object>} window.YT
 */
export const loadApi = () => {
    if (apiPromise) {
        return apiPromise;
    }
    apiPromise = new Promise((resolve, reject) => {
        if (window.YT && window.YT.Player) {
            resolve(window.YT);
            return;
        }
        const previous = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = () => {
            if (typeof previous === 'function') {
                previous();
            }
            resolve(window.YT);
        };
        const script = document.createElement('script');
        script.src = API_URL;
        script.async = true;
        script.onerror = () => {
            apiPromise = null;
            reject(new Error('YouTube API could not be loaded'));
        };
        document.head.appendChild(script);
    });
    return apiPromise;
};

/**
 * A YouTube video bound to a container.
 */
export class Video {
    /**
     * Creates the player.
     *
     * @param {HTMLElement} container
     * @param {string} videoid
     * @param {object} handlers onReady, onState(state: playing|paused|ended|buffering), onError
     */
    constructor(container, videoid, handlers = {}) {
        this.handlers = handlers;
        this.ready = false;
        this.rate = 1;
        const host = document.createElement('div');
        container.appendChild(host);
        this.promise = loadApi().then((YT) => new Promise((resolve) => {
            this.YT = YT;
            this.player = new YT.Player(host, {
                host: 'https://www.youtube-nocookie.com',
                videoId: videoid,
                width: '100%',
                height: '100%',
                playerVars: {
                    autoplay: 0,
                    controls: 0,
                    disablekb: 1,
                    fs: 0,
                    rel: 0,
                    modestbranding: 1,
                    playsinline: 1,
                    iv_load_policy: 3, // eslint-disable-line camelcase
                    enablejsapi: 1,
                    origin: window.location.origin,
                },
                events: {
                    onReady: () => {
                        this.ready = true;
                        if (handlers.onReady) {
                            handlers.onReady();
                        }
                        resolve(this);
                    },
                    onStateChange: (e) => this.stateChange(e.data),
                    onError: (e) => handlers.onError && handlers.onError(e.data),
                },
            });
        }));
    }

    /**
     * Maps YouTube states to names.
     *
     * @param {number} code
     */
    stateChange(code) {
        const S = this.YT.PlayerState;
        const map = {[S.PLAYING]: 'playing', [S.PAUSED]: 'paused', [S.ENDED]: 'ended', [S.BUFFERING]: 'buffering',
            [S.CUED]: 'cued'};
        if (this.handlers.onState && map[code]) {
            this.handlers.onState(map[code]);
        }
    }

    /** Plays. */
    play() {
        if (this.ready) {
            this.player.playVideo();
        }
    }

    /** Pauses. */
    pause() {
        if (this.ready) {
            this.player.pauseVideo();
        }
    }

    /**
     * Seeks.
     *
     * @param {number} seconds
     */
    seek(seconds) {
        if (this.ready) {
            this.player.seekTo(Math.max(0, seconds), true);
        }
    }

    /**
     * Current time.
     *
     * @returns {number}
     */
    time() {
        return this.ready ? (this.player.getCurrentTime() || 0) : 0;
    }

    /**
     * Duration.
     *
     * @returns {number}
     */
    duration() {
        return this.ready ? (this.player.getDuration() || 0) : 0;
    }

    /**
     * Whether it is playing.
     *
     * @returns {boolean}
     */
    playing() {
        return this.ready && this.player.getPlayerState() === this.YT.PlayerState.PLAYING;
    }

    /**
     * Sets the playback rate.
     *
     * @param {number} rate
     */
    setRate(rate) {
        this.rate = rate;
        if (this.ready) {
            this.player.setPlaybackRate(rate);
        }
    }

    /**
     * Mutes or unmutes the video sound.
     *
     * @param {boolean} on
     */
    setMuted(on) {
        if (!this.ready) {
            return;
        }
        if (on) {
            this.player.mute();
        } else {
            this.player.unMute();
        }
    }

    /**
     * Whether the video sound is muted.
     *
     * @returns {boolean}
     */
    isMuted() {
        return this.ready ? this.player.isMuted() : false;
    }

    /** Removes the player. */
    destroy() {
        if (this.player && this.player.destroy) {
            this.player.destroy();
        }
    }
}
