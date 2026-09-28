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
 * Visual effects: confetti, particle bursts, floating points and FLIP animations.
 *
 * @module     mod_aiinteractivevideo/fx
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const REDUCED = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
export const EASE = 'cubic-bezier(.22,1,.36,1)';
export const SPRING = 'cubic-bezier(.34,1.56,.64,1)';

/** @type {number} How much the card is scaled up or down in full screen (1 outside full screen). */
let viewScale = 1;

/**
 * Sets the full screen scale so pointer and animation distances can be converted to layout pixels.
 *
 * @param {number} scale
 */
export const setScale = (scale) => {
    viewScale = scale > 0 ? scale : 1;
};

/**
 * Current full screen scale (1 when not scaled).
 *
 * @returns {number}
 */
export const getScale = () => viewScale;

const COLORS = ['#6366F1', '#0EA5E9', '#10B981', '#F59E0B', '#EF4444', '#EC4899', '#8B5CF6'];

/**
 * Confetti burst over a host element.
 *
 * @param {HTMLElement} host positioned element
 * @param {number} amount
 * @param {number} originY 0..1 vertical origin
 */
export const confetti = (host, amount = 140, originY = 0.35) => {
    if (REDUCED || !host) {
        return;
    }
    const canvas = document.createElement('canvas');
    canvas.className = 'aiv-confetti';
    canvas.setAttribute('aria-hidden', 'true');
    host.appendChild(canvas);
    const rect = host.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    const c = canvas.getContext('2d');
    c.scale(dpr, dpr);
    const parts = Array.from({length: amount}, () => ({
        x: rect.width / 2 + (Math.random() - 0.5) * rect.width * 0.35,
        y: rect.height * originY,
        vx: (Math.random() - 0.5) * 15,
        vy: -Math.random() * 13 - 4,
        r: Math.random() * 6 + 4,
        a: Math.random() * Math.PI,
        va: (Math.random() - 0.5) * 0.3,
        color: COLORS[Math.floor(Math.random() * COLORS.length)],
        shape: Math.random() > 0.5,
    }));
    const start = performance.now();
    const frame = (now) => {
        const t = now - start;
        c.clearRect(0, 0, rect.width, rect.height);
        parts.forEach((p) => {
            p.vy += 0.35;
            p.vx *= 0.985;
            p.x += p.vx;
            p.y += p.vy;
            p.a += p.va;
            c.save();
            c.globalAlpha = Math.max(0, 1 - t / 2600);
            c.translate(p.x, p.y);
            c.rotate(p.a);
            c.fillStyle = p.color;
            if (p.shape) {
                c.fillRect(-p.r / 2, -p.r / 4, p.r, p.r / 2);
            } else {
                c.beginPath();
                c.arc(0, 0, p.r / 2.6, 0, Math.PI * 2);
                c.fill();
            }
            c.restore();
        });
        if (t < 2600) {
            requestAnimationFrame(frame);
        } else {
            canvas.remove();
        }
    };
    requestAnimationFrame(frame);
};

/**
 * Particle burst around an element (correct answer sparkle).
 *
 * @param {HTMLElement} target
 * @param {string} color
 * @param {number} count
 */
export const burst = (target, color = '#12b76a', count = 14) => {
    if (REDUCED || !target) {
        return;
    }
    const rect = target.getBoundingClientRect();
    const layer = document.createElement('div');
    layer.className = 'aiv-burst-layer';
    (document.fullscreenElement || document.body).appendChild(layer);
    const x = rect.left + rect.width / 2;
    const y = rect.top + rect.height / 2;
    for (let i = 0; i < count; i++) {
        const p = document.createElement('span');
        p.className = 'aiv-particle';
        const angle = (Math.PI * 2 * i) / count + Math.random() * 0.4;
        const dist = Math.max(rect.width, rect.height) / 2 + 12 + Math.random() * 26;
        p.style.left = `${x}px`;
        p.style.top = `${y}px`;
        p.style.background = i % 3 === 0 ? '#FACC15' : color;
        layer.appendChild(p);
        p.animate([
            {transform: 'translate(-50%, -50%) scale(1)', opacity: 1},
            {transform: `translate(calc(-50% + ${Math.cos(angle) * dist}px), calc(-50% + ${Math.sin(angle) * dist}px))
                scale(0.2)`, opacity: 0},
        ], {duration: 650 + Math.random() * 250, easing: 'cubic-bezier(.2,.8,.3,1)'});
    }
    window.setTimeout(() => layer.remove(), 1000);
};

/**
 * Floats a label (e.g. "+1") from one element towards another.
 *
 * @param {HTMLElement} from
 * @param {HTMLElement|null} to
 * @param {string} text
 */
export const floatPoints = (from, to, text) => {
    if (!from) {
        return;
    }
    const a = from.getBoundingClientRect();
    const node = document.createElement('span');
    node.className = 'aiv-floatpoint';
    node.textContent = text;
    (document.fullscreenElement || document.body).appendChild(node);
    node.style.left = `${a.left + a.width / 2}px`;
    node.style.top = `${a.top + a.height / 2}px`;
    if (REDUCED) {
        window.setTimeout(() => node.remove(), 600);
        return;
    }
    let dx = 0;
    let dy = -70;
    if (to && !to.hidden) {
        const b = to.getBoundingClientRect();
        dx = b.left + b.width / 2 - (a.left + a.width / 2);
        dy = b.top + b.height / 2 - (a.top + a.height / 2);
    }
    node.animate([
        {transform: 'translate(-50%, -50%) scale(.6)', opacity: 0},
        {transform: 'translate(-50%, -120%) scale(1.25)', opacity: 1, offset: 0.25},
        {transform: `translate(calc(-50% + ${dx}px), calc(-50% + ${dy}px)) scale(.7)`, opacity: 0.2},
    ], {duration: 1100, easing: EASE}).finished.then(() => node.remove()).catch(() => node.remove());
};

/**
 * FLIP animation from a previous rect to the element's current position.
 *
 * @param {HTMLElement} node
 * @param {DOMRect} from
 * @param {number} duration
 * @returns {Promise}
 */
export const flip = (node, from, duration = 360) => {
    const to = node.getBoundingClientRect();
    if (REDUCED || !node.animate || !to.width || !from) {
        return Promise.resolve();
    }
    // Screen distances are converted to layout pixels when the card is scaled in full screen.
    const dx = (from.left - to.left) / viewScale;
    const dy = (from.top - to.top) / viewScale;
    if (Math.abs(dx) < 1 && Math.abs(dy) < 1) {
        return Promise.resolve();
    }
    return node.animate([
        {transform: `translate(${dx}px, ${dy}px)`},
        {transform: 'none'},
    ], {duration, easing: SPRING}).finished.catch(() => null);
};

/**
 * Shakes an element.
 *
 * @param {HTMLElement} node
 */
export const shake = (node) => {
    if (REDUCED || !node || !node.animate) {
        return;
    }
    node.animate([
        {transform: 'translateX(0)'}, {transform: 'translateX(-7px)'}, {transform: 'translateX(6px)'},
        {transform: 'translateX(-4px)'}, {transform: 'translateX(3px)'}, {transform: 'translateX(0)'},
    ], {duration: 420, easing: 'ease-out'});
};

/**
 * Pops an element (scale bounce).
 *
 * @param {HTMLElement} node
 * @param {number} scale
 */
export const pop = (node, scale = 1.12) => {
    if (REDUCED || !node || !node.animate) {
        return;
    }
    node.animate([{transform: 'scale(1)'}, {transform: `scale(${scale})`}, {transform: 'scale(1)'}],
        {duration: 320, easing: SPRING});
};

/**
 * Counts a number up inside an element.
 *
 * @param {HTMLElement} node
 * @param {number} value
 * @param {number} duration
 */
export const countUp = (node, value, duration = 1400) => {
    if (REDUCED) {
        node.textContent = Math.round(value);
        return;
    }
    const t0 = performance.now();
    const step = (now) => {
        const k = Math.min(1, (now - t0) / duration);
        node.textContent = Math.round(value * (1 - Math.pow(1 - k, 3)));
        if (k < 1) {
            requestAnimationFrame(step);
        }
    };
    requestAnimationFrame(step);
};
