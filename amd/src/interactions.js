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
 * The eight interaction types: rendering (Mustache templates) and behaviour.
 *
 * Every type supports mouse, touch and pen dragging where it makes sense, a tap-tap alternative, and
 * keyboard use. Answers are never known here: the server marks responses and returns per-item results,
 * hint assists and (in Learn mode) the solution, all as key/value pairs.
 *
 * @module     mod_aiinteractivevideo/interactions
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';
import * as Sound from 'mod_aiinteractivevideo/sound';
import * as Fx from 'mod_aiinteractivevideo/fx';

/** Icon for each type (see the icon template). */
export const ICONS = {
    cardselect: 'cards',
    categorize: 'columns',
    fillblanks: 'gaps',
    ordering: 'list',
    matching: 'link',
    spotmistake: 'search',
    swipe: 'swipe',
    unscramble: 'letters',
};

const PAIR_COLORS = ['#6366F1', '#0EA5E9', '#F59E0B', '#EC4899', '#10B981', '#8B5CF6'];
const SVGNS = 'http://www.w3.org/2000/svg';

/**
 * Numbers a list for templates.
 *
 * @param {Array} list
 * @returns {Array}
 */
const numbered = (list) => (list || []).map((x, i) => Object.assign({n: i + 1}, x));

/**
 * Pointer drag helper with a floating ghost. A press without movement is left to the click handler.
 */
class Dragger {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root area that contains sources and drop targets
     * @param {object} opts source (selector), canDrag(el), onDrop(el, target|null), onOver(target|null)
     */
    constructor(root, opts) {
        this.root = root;
        this.opts = opts;
        this.state = null;
        this.suppress = false;
        this.down = (e) => this.onDown(e);
        this.move = (e) => this.onMove(e);
        this.up = (e) => this.onUp(e);
        root.addEventListener('pointerdown', this.down);
        root.addEventListener('click', (e) => {
            if (this.suppress) {
                e.stopPropagation();
                e.preventDefault();
                this.suppress = false;
            }
        }, true);
    }

    /**
     * Pointer down.
     *
     * @param {PointerEvent} e
     */
    onDown(e) {
        if (e.button !== undefined && e.button !== 0) {
            return;
        }
        const el = e.target.closest(this.opts.source);
        if (!el || !this.root.contains(el) || (this.opts.canDrag && !this.opts.canDrag(el))) {
            return;
        }
        this.state = {el, x: e.clientX, y: e.clientY, active: false, over: null, id: e.pointerId};
        window.addEventListener('pointermove', this.move);
        window.addEventListener('pointerup', this.up);
        window.addEventListener('pointercancel', this.up);
    }

    /**
     * Pointer move.
     *
     * @param {PointerEvent} e
     */
    onMove(e) {
        const s = this.state;
        if (!s || e.pointerId !== s.id) {
            return;
        }
        if (!s.active) {
            if (Math.hypot(e.clientX - s.x, e.clientY - s.y) < 6) {
                return;
            }
            s.active = true;
            const r = s.el.getBoundingClientRect();
            s.dx = s.x - r.left;
            s.dy = s.y - r.top;
            s.ghost = s.el.cloneNode(true);
            s.ghost.classList.add('aiv-ghost');
            // The ghost lives outside the scaled card, so it is drawn at layout size and scaled to match.
            s.scale = Fx.getScale();
            s.ghost.style.width = `${r.width / s.scale}px`;
            s.ghost.style.height = `${r.height / s.scale}px`;
            s.ghost.style.transformOrigin = '0 0';
            (document.fullscreenElement || document.body).appendChild(s.ghost);
            s.el.classList.add('is-dragging');
            this.root.classList.add('is-dragging-any');
            Sound.play('pickup');
        }
        e.preventDefault();
        const tilt = Math.max(-8, Math.min(8, (e.clientX - (s.lastX || e.clientX)) * 0.8));
        s.lastX = e.clientX;
        const at = `translate(${e.clientX - s.dx}px, ${e.clientY - s.dy}px)`;
        s.ghost.style.transform = `${at} scale(${s.scale}) rotate(${tilt}deg)`;
        s.ghost.hidden = true;
        const under = document.elementFromPoint(e.clientX, e.clientY);
        s.ghost.hidden = false;
        const target = under ? under.closest('[data-drop]') : null;
        const over = target && this.root.contains(target) ? target : null;
        if (over !== s.over) {
            if (s.over) {
                s.over.classList.remove('is-over');
            }
            if (over) {
                over.classList.add('is-over');
                Sound.play('zone');
            }
            s.over = over;
        }
    }

    /**
     * Pointer up.
     *
     * @param {PointerEvent} e
     */
    onUp(e) {
        const s = this.state;
        if (!s || (e.pointerId !== undefined && e.pointerId !== s.id)) {
            return;
        }
        window.removeEventListener('pointermove', this.move);
        window.removeEventListener('pointerup', this.up);
        window.removeEventListener('pointercancel', this.up);
        this.state = null;
        if (!s.active) {
            return;
        }
        this.suppress = true;
        window.setTimeout(() => {
            this.suppress = false;
        }, 50);
        if (s.over) {
            s.over.classList.remove('is-over');
        }
        s.el.classList.remove('is-dragging');
        this.root.classList.remove('is-dragging-any');
        const ghostRect = s.ghost.getBoundingClientRect();
        s.ghost.remove();
        this.opts.onDrop(s.el, s.over, ghostRect);
    }
}

/**
 * Base controller.
 */
class Base {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root body element
     * @param {object} section section data
     * @param {object} ctx onChange(complete), strings S, fmt()
     */
    constructor(root, section, ctx) {
        this.root = root;
        this.section = section;
        this.ctx = ctx;
        this.locked = false;
        this.selected = null;
        this.init();
        window.setTimeout(() => this.changed(), 0);
    }

    /** Sets up behaviour. */
    init() {
        // Implemented by types.
    }

    /** Notifies the card that completeness may have changed. */
    changed() {
        this.ctx.onChange(this.isComplete());
    }

    /**
     * Whether the learner has answered everything.
     *
     * @returns {boolean}
     */
    isComplete() {
        return true;
    }

    /**
     * The response as key/value pairs.
     *
     * @returns {Array}
     */
    response() {
        return [];
    }

    /**
     * Marks an element right or wrong.
     *
     * @param {HTMLElement} el
     * @param {boolean} ok
     */
    mark(el, ok) {
        if (!el) {
            return;
        }
        el.classList.remove('is-right', 'is-wrong');
        // Force reflow so the animation restarts.
        void el.offsetWidth;
        el.classList.add(ok ? 'is-right' : 'is-wrong');
        if (ok) {
            Fx.burst(el, '#12b76a', 10);
        } else {
            Fx.shake(el);
        }
    }

    /** Removes result marks (before a new try). */
    clearMarks() {
        this.root.querySelectorAll('.is-right:not(.is-fixed), .is-wrong').forEach((el) => {
            el.classList.remove('is-right', 'is-wrong');
        });
    }

    /**
     * Shows per-item results.
     *
     * @param {Array} results key, value, correct
     */
    showResults(results) {
        results.forEach((r) => this.mark(this.find(r.key), !!r.correct));
    }

    /**
     * Finds the element for a key.
     *
     * @param {string} key
     * @returns {HTMLElement|null}
     */
    find(key) {
        return this.root.querySelector(`[data-token="${CSS.escape(key)}"]`);
    }

    /**
     * Applies hint assists.
     *
     * @param {Array} list
     */
    assist(list) {
        list.forEach((a) => this.place(a, true));
        this.changed();
    }

    /**
     * Shows the full answer.
     *
     * @param {Array} list
     */
    solve(list) {
        this.clearMarks();
        list.forEach((a) => this.place(a, true));
        this.lock();
    }

    /**
     * Places one known answer (and fixes it in place).
     *
     * @param {object} pair
     * @param {boolean} fixed
     */
    place(pair, fixed) { // eslint-disable-line no-unused-vars
        // Implemented by types.
    }

    /** Stops further changes. */
    lock() {
        this.locked = true;
        this.root.classList.add('is-locked');
        this.root.querySelectorAll('button').forEach((b) => {
            b.tabIndex = -1;
        });
    }

    /**
     * Tap-select helper.
     *
     * @param {HTMLElement|null} el
     */
    select(el) {
        if (this.selected) {
            this.selected.classList.remove('is-selected');
            this.selected.setAttribute('aria-pressed', 'false');
        }
        this.selected = el;
        if (el) {
            el.classList.add('is-selected');
            el.setAttribute('aria-pressed', 'true');
            Sound.play('select');
        }
        this.root.classList.toggle('has-selection', !!el);
    }

    /**
     * Moves a node into a container with a FLIP animation.
     *
     * @param {HTMLElement} node
     * @param {HTMLElement} container
     * @param {HTMLElement|null} before
     */
    moveInto(node, container, before = null) {
        const from = node.getBoundingClientRect();
        container.insertBefore(node, before);
        Fx.flip(node, from);
    }
}

/**
 * Card select.
 */
class CardSelect extends Base {
    /** Init. */
    init() {
        this.need = this.section.selectcount || 0;
        this.root.addEventListener('click', (e) => {
            const card = e.target.closest('.aiv-selcard');
            if (!card || this.locked || card.disabled) {
                return;
            }
            const on = card.getAttribute('aria-pressed') !== 'true';
            if (on && this.need && this.count() >= this.need) {
                Fx.shake(card);
                Sound.play('back');
                return;
            }
            card.setAttribute('aria-pressed', on ? 'true' : 'false');
            card.classList.toggle('is-selected', on);
            card.classList.remove('is-right', 'is-wrong');
            Sound.play(on ? 'flip' : 'back');
            Fx.pop(card, 1.05);
            this.updateCounter();
            this.changed();
        });
        this.updateCounter();
    }

    /**
     * Selected count.
     *
     * @returns {number}
     */
    count() {
        return this.root.querySelectorAll('.aiv-selcard.is-selected').length;
    }

    /** Updates "1 of 3 selected". */
    updateCounter() {
        const el = this.ctx.card.querySelector('[data-region="selectcount"]');
        if (el) {
            el.textContent = this.ctx.fmt(this.ctx.S.selectedof, {count: this.count(), total: this.need});
        }
    }

    /**
     * Complete when the required number is selected.
     *
     * @returns {boolean}
     */
    isComplete() {
        return this.need ? this.count() === this.need : this.count() > 0;
    }

    /**
     * Response.
     *
     * @returns {Array}
     */
    response() {
        return Array.from(this.root.querySelectorAll('.aiv-selcard.is-selected'))
            .map((c) => ({key: c.dataset.token, value: '1'}));
    }

    /**
     * Shows the answer: only the correct cards stay selected.
     *
     * @param {Array} list
     */
    solve(list) {
        this.root.querySelectorAll('.aiv-selcard').forEach((c) => {
            c.classList.remove('is-selected', 'is-wrong');
            c.setAttribute('aria-pressed', 'false');
        });
        super.solve(list);
    }

    /**
     * Assist: rule out wrong cards.
     *
     * @param {object} pair
     */
    place(pair) {
        const card = this.find(pair.key);
        if (!card) {
            return;
        }
        if (pair.value === 'eliminate') {
            card.classList.remove('is-selected');
            card.setAttribute('aria-pressed', 'false');
            card.classList.add('is-eliminated');
            card.disabled = true;
        } else {
            card.classList.add('is-selected', 'is-right', 'is-fixed');
            card.setAttribute('aria-pressed', 'true');
        }
        this.updateCounter();
    }
}

/**
 * Category sort.
 */
class Categorize extends Base {
    /** Init. */
    init() {
        this.tray = this.root.querySelector('[data-region="tray"]');
        this.dragger = new Dragger(this.root, {
            source: '.aiv-chip',
            canDrag: (el) => !this.locked && !el.classList.contains('is-fixed'),
            onDrop: (el, target) => {
                if (target) {
                    this.drop(el, target);
                } else {
                    Sound.play('back');
                }
            },
        });
        this.root.addEventListener('click', (e) => {
            if (this.locked) {
                return;
            }
            const chip = e.target.closest('.aiv-chip');
            if (chip) {
                if (chip.classList.contains('is-fixed')) {
                    return;
                }
                this.select(this.selected === chip ? null : chip);
                return;
            }
            const bin = e.target.closest('[data-drop]');
            if (bin && this.selected) {
                this.drop(this.selected, bin);
            }
        });
        this.root.addEventListener('keydown', (e) => {
            const bin = e.target.closest('.aiv-bin');
            if (bin && (e.key === 'Enter' || e.key === ' ') && this.selected) {
                e.preventDefault();
                this.drop(this.selected, bin);
            }
        });
    }

    /**
     * Drops a chip onto a bin or the tray.
     *
     * @param {HTMLElement} chip
     * @param {HTMLElement} target
     */
    drop(chip, target) {
        const container = target.dataset.drop === 'tray' ? this.tray : target.querySelector('[data-region="binitems"]');
        chip.classList.remove('is-right', 'is-wrong');
        this.moveInto(chip, container);
        this.select(null);
        Sound.play('drop');
        Fx.pop(target, 1.03);
        this.root.classList.toggle('tray-empty', !this.tray.querySelector('.aiv-chip'));
        this.changed();
    }

    /**
     * Complete when the tray is empty.
     *
     * @returns {boolean}
     */
    isComplete() {
        return !this.tray.querySelector('.aiv-chip');
    }

    /**
     * Response.
     *
     * @returns {Array}
     */
    response() {
        return Array.from(this.root.querySelectorAll('.aiv-bin .aiv-chip')).map((c) => ({
            key: c.dataset.token,
            value: c.closest('.aiv-bin').dataset.token,
        }));
    }

    /**
     * Places an item in its bin.
     *
     * @param {object} pair
     */
    place(pair) {
        const chip = this.find(pair.key);
        const bin = this.root.querySelector(`.aiv-bin[data-token="${CSS.escape(pair.value)}"]`);
        if (chip && bin) {
            this.moveInto(chip, bin.querySelector('[data-region="binitems"]'));
            chip.classList.add('is-right', 'is-fixed');
        }
        this.root.classList.toggle('tray-empty', !this.tray.querySelector('.aiv-chip'));
    }
}

/**
 * Fill the gaps.
 */
class FillBlanks extends Base {
    /** Init. */
    init() {
        this.tray = this.root.querySelector('[data-region="tray"]');
        this.filled = new Map();
        this.dragger = new Dragger(this.root, {
            source: '.aiv-chip',
            canDrag: (el) => !this.locked && !el.classList.contains('is-used'),
            onDrop: (el, target) => {
                if (target && target.dataset.drop === 'gap') {
                    this.fill(target, el);
                } else {
                    Sound.play('back');
                }
            },
        });
        this.root.addEventListener('click', (e) => {
            if (this.locked) {
                return;
            }
            const chip = e.target.closest('.aiv-chip');
            if (chip) {
                this.select(this.selected === chip ? null : chip);
                if (this.selected) {
                    this.root.querySelectorAll('.aiv-gap:not(.is-filled)').forEach((g) => g.classList.add('is-target'));
                } else {
                    this.clearTargets();
                }
                return;
            }
            const gap = e.target.closest('.aiv-gap');
            if (!gap || gap.classList.contains('is-fixed')) {
                return;
            }
            if (this.selected) {
                this.fill(gap, this.selected);
            } else if (this.filled.has(gap.dataset.token)) {
                this.empty(gap);
                Sound.play('back');
                this.changed();
            } else {
                // Point at the word bank.
                Fx.shake(this.tray);
            }
        });
    }

    /** Clears gap highlights. */
    clearTargets() {
        this.root.querySelectorAll('.aiv-gap.is-target').forEach((g) => g.classList.remove('is-target'));
    }

    /**
     * Puts a word in a gap.
     *
     * @param {HTMLElement} gap
     * @param {HTMLElement} chip
     * @param {boolean} fixed
     */
    fill(gap, chip, fixed = false) {
        if (this.filled.has(gap.dataset.token)) {
            this.empty(gap);
        }
        // If the chip already sits in another gap, take it from there.
        this.filled.forEach((token, gaptoken) => {
            if (token === chip.dataset.token) {
                this.empty(this.find(gaptoken));
            }
        });
        const from = chip.getBoundingClientRect();
        this.filled.set(gap.dataset.token, chip.dataset.token);
        gap.querySelector('.aiv-gap-text').textContent = chip.textContent.trim();
        gap.classList.add('is-filled');
        gap.classList.remove('is-right', 'is-wrong');
        chip.classList.add('is-used');
        chip.classList.remove('is-selected');
        this.selected = null;
        this.root.classList.remove('has-selection');
        this.clearTargets();
        Fx.flip(gap.querySelector('.aiv-gap-text'), from);
        Sound.play('drop');
        if (fixed) {
            gap.classList.add('is-fixed', 'is-right');
        }
        this.changed();
    }

    /**
     * Empties a gap.
     *
     * @param {HTMLElement} gap
     */
    empty(gap) {
        const token = this.filled.get(gap.dataset.token);
        this.filled.delete(gap.dataset.token);
        gap.querySelector('.aiv-gap-text').textContent = '';
        gap.classList.remove('is-filled', 'is-right', 'is-wrong');
        const chip = this.root.querySelector(`.aiv-chip[data-token="${CSS.escape(token)}"]`);
        if (chip) {
            chip.classList.remove('is-used');
            Fx.pop(chip);
        }
    }

    /**
     * Complete when every gap is filled.
     *
     * @returns {boolean}
     */
    isComplete() {
        return this.filled.size === this.root.querySelectorAll('.aiv-gap').length;
    }

    /**
     * Response.
     *
     * @returns {Array}
     */
    response() {
        return Array.from(this.filled.entries()).map(([key, value]) => ({key, value}));
    }

    /**
     * Places a word.
     *
     * @param {object} pair
     */
    place(pair) {
        const gap = this.find(pair.key);
        const chip = this.root.querySelector(`.aiv-chip[data-token="${CSS.escape(pair.value)}"]`);
        if (gap && chip) {
            this.fill(gap, chip, true);
        }
    }
}

/**
 * Sequence.
 */
class Ordering extends Base {
    /** Init. */
    init() {
        this.list = this.root.querySelector('[data-region="list"]');
        this.pins = new Map();
        this.renumber();
        this.list.addEventListener('pointerdown', (e) => this.down(e));
        this.list.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-move]');
            if (!btn || this.locked) {
                return;
            }
            const item = btn.closest('.aiv-order-item');
            this.moveBy(item, parseInt(btn.dataset.move, 10));
            btn.focus();
        });
    }

    /**
     * Items in order.
     *
     * @returns {HTMLElement[]}
     */
    items() {
        return Array.from(this.list.querySelectorAll('.aiv-order-item'));
    }

    /** Updates the numbers. */
    renumber() {
        this.items().forEach((item, i) => {
            item.querySelector('.aiv-order-n').textContent = i + 1;
        });
    }

    /**
     * Moves an item up or down.
     *
     * @param {HTMLElement} item
     * @param {number} delta
     */
    moveBy(item, delta) {
        const items = this.items();
        const index = items.indexOf(item);
        const to = index + delta;
        if (to < 0 || to >= items.length || item.classList.contains('is-fixed')) {
            return;
        }
        this.reorder(() => {
            if (delta < 0) {
                this.list.insertBefore(item, items[to]);
            } else {
                this.list.insertBefore(item, items[to].nextSibling);
            }
        });
        Sound.play('drop');
        this.clearMarks();
    }

    /**
     * Runs a DOM change with FLIP on all items.
     *
     * @param {Function} change
     * @param {HTMLElement|null} skip item not to animate
     */
    reorder(change, skip = null) {
        const rects = new Map(this.items().map((el) => [el, el.getBoundingClientRect()]));
        change();
        this.enforcePins();
        this.renumber();
        this.items().forEach((el) => {
            if (el !== skip) {
                Fx.flip(el, rects.get(el), 260);
            }
        });
        this.changed();
    }

    /** Keeps hinted items at their positions. */
    enforcePins() {
        this.pins.forEach((pos, token) => {
            const item = this.find(token);
            const items = this.items();
            if (item && items.indexOf(item) !== pos) {
                const others = items.filter((x) => x !== item);
                this.list.insertBefore(item, others[pos] || null);
            }
        });
    }

    /**
     * Starts a drag.
     *
     * @param {PointerEvent} e
     */
    down(e) {
        const item = e.target.closest('.aiv-order-item');
        if (!item || this.locked || e.target.closest('button') || item.classList.contains('is-fixed')) {
            return;
        }
        e.preventDefault();
        const startY = e.clientY;
        const scale = Fx.getScale();
        const grab = (startY - item.getBoundingClientRect().top) / scale;
        let active = false;
        const move = (ev) => {
            if (!active) {
                if (Math.abs(ev.clientY - startY) < 5) {
                    return;
                }
                active = true;
                item.classList.add('is-lifted');
                Sound.play('pickup');
                this.clearMarks();
            }
            ev.preventDefault();
            // Layout positions (offsetTop) are not affected by running animations.
            const y = (ev.clientY - this.list.getBoundingClientRect().top) / scale;
            const others = this.items().filter((x) => x !== item);
            let before = null;
            for (const other of others) {
                if (y < other.offsetTop + other.offsetHeight / 2) {
                    before = other;
                    break;
                }
            }
            const changed = before ? item.nextElementSibling !== before : !!item.nextElementSibling;
            if (changed) {
                this.reorder(() => this.list.insertBefore(item, before), item);
                Sound.play('zone');
            }
            const translate = y - grab - item.offsetTop;
            item.style.transform = `translateY(${translate}px) scale(1.02)`;
        };
        const up = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('pointercancel', up);
            if (!active) {
                return;
            }
            const from = item.getBoundingClientRect();
            item.style.transform = '';
            item.classList.remove('is-lifted');
            Fx.flip(item, from, 220);
            Sound.play('drop');
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        window.addEventListener('pointercancel', up);
    }

    /**
     * Response: position keys p0, p1... with tokens.
     *
     * @returns {Array}
     */
    response() {
        return this.items().map((el, i) => ({key: `p${i}`, value: el.dataset.token}));
    }

    /**
     * Marks positions.
     *
     * @param {Array} results
     */
    showResults(results) {
        const items = this.items();
        results.forEach((r) => {
            const pos = parseInt(String(r.key).slice(1), 10);
            this.mark(items[pos], !!r.correct);
        });
    }

    /**
     * Moves an item to its correct position and pins it.
     *
     * @param {object} pair
     */
    place(pair) {
        const pos = parseInt(String(pair.key).slice(1), 10);
        const item = this.find(pair.value);
        if (!item) {
            return;
        }
        this.pins.set(pair.value, pos);
        item.classList.add('is-fixed', 'is-right');
        this.reorder(() => this.enforcePins());
    }

    /**
     * Shows the whole order.
     *
     * @param {Array} list
     */
    solve(list) {
        this.clearMarks();
        list.forEach((p) => this.pins.set(p.value, parseInt(String(p.key).slice(1), 10)));
        this.reorder(() => {
            list.slice().sort((a, b) => parseInt(a.key.slice(1), 10) - parseInt(b.key.slice(1), 10))
                .forEach((p) => {
                    const el = this.find(p.value);
                    if (el) {
                        this.list.appendChild(el);
                    }
                });
        });
        this.items().forEach((el) => el.classList.add('is-right', 'is-fixed'));
        this.lock();
    }
}

/**
 * Match up.
 */
class Matching extends Base {
    /** Init. */
    init() {
        this.svg = this.root.querySelector('[data-region="lines"]');
        this.pairs = new Map();
        this.colors = new Map();
        this.pending = null;
        this.root.addEventListener('click', (e) => {
            const item = e.target.closest('.aiv-match-item');
            if (!item || this.locked || item.classList.contains('is-fixed')) {
                return;
            }
            this.tap(item);
        });
        this.observer = window.ResizeObserver ? new ResizeObserver(() => this.draw()) : null;
        if (this.observer) {
            this.observer.observe(this.root.querySelector('.aiv-match'));
        }
    }

    /**
     * Tap on an item.
     *
     * @param {HTMLElement} item
     */
    tap(item) {
        const side = item.dataset.side;
        const token = item.dataset.token;
        // Tapping a connected item disconnects it (unless we are completing a new pair).
        if (!this.pending) {
            const partner = this.partnerOf(side, token);
            if (partner) {
                this.disconnect(side === 'left' ? token : partner);
                Sound.play('back');
                this.changed();
                return;
            }
            this.pending = item;
            item.classList.add('is-selected');
            Sound.play('select');
            return;
        }
        if (this.pending === item) {
            item.classList.remove('is-selected');
            this.pending = null;
            return;
        }
        if (this.pending.dataset.side === side) {
            this.pending.classList.remove('is-selected');
            this.pending = item;
            item.classList.add('is-selected');
            Sound.play('select');
            return;
        }
        const left = side === 'left' ? token : this.pending.dataset.token;
        const right = side === 'right' ? token : this.pending.dataset.token;
        this.pending.classList.remove('is-selected');
        this.pending = null;
        this.connect(left, right);
        Sound.play('drop');
        this.changed();
    }

    /**
     * The partner of a token.
     *
     * @param {string} side
     * @param {string} token
     * @returns {string|null}
     */
    partnerOf(side, token) {
        if (side === 'left') {
            return this.pairs.get(token) || null;
        }
        for (const [l, r] of this.pairs) {
            if (r === token) {
                return l;
            }
        }
        return null;
    }

    /**
     * Connects a pair.
     *
     * @param {string} left
     * @param {string} right
     */
    connect(left, right) {
        this.disconnect(left);
        const other = this.partnerOf('right', right);
        if (other) {
            this.disconnect(other);
        }
        this.pairs.set(left, right);
        const used = new Set(this.colors.values());
        const color = PAIR_COLORS.find((c) => !used.has(c)) || PAIR_COLORS[this.pairs.size % PAIR_COLORS.length];
        this.colors.set(left, color);
        [this.side('left', left), this.side('right', right)].forEach((el) => {
            el.classList.add('is-paired');
            el.classList.remove('is-right', 'is-wrong');
            el.style.setProperty('--pair', color);
            Fx.pop(el, 1.04);
        });
        this.draw();
    }

    /**
     * Disconnects a left item.
     *
     * @param {string} left
     */
    disconnect(left) {
        const right = this.pairs.get(left);
        if (!right) {
            return;
        }
        this.pairs.delete(left);
        this.colors.delete(left);
        [this.side('left', left), this.side('right', right)].forEach((el) => {
            el.classList.remove('is-paired', 'is-right', 'is-wrong');
            el.style.removeProperty('--pair');
        });
        this.draw();
    }

    /**
     * Item element.
     *
     * @param {string} side
     * @param {string} token
     * @returns {HTMLElement}
     */
    side(side, token) {
        return this.root.querySelector(`.aiv-match-item[data-side="${side}"][data-token="${CSS.escape(token)}"]`);
    }

    /** Draws connector lines. */
    draw() {
        const box = this.root.querySelector('.aiv-match');
        const b = box.getBoundingClientRect();
        this.svg.setAttribute('viewBox', `0 0 ${b.width} ${b.height}`);
        this.svg.replaceChildren();
        this.pairs.forEach((right, left) => {
            const l = this.side('left', left).querySelector('.aiv-match-dot').getBoundingClientRect();
            const r = this.side('right', right).querySelector('.aiv-match-dot').getBoundingClientRect();
            const x1 = l.left + l.width / 2 - b.left;
            const y1 = l.top + l.height / 2 - b.top;
            const x2 = r.left + r.width / 2 - b.left;
            const y2 = r.top + r.height / 2 - b.top;
            const mx = (x1 + x2) / 2;
            const path = document.createElementNS(SVGNS, 'path');
            path.setAttribute('d', `M${x1} ${y1} C${mx} ${y1} ${mx} ${y2} ${x2} ${y2}`);
            const leftEl = this.side('left', left);
            let state = '';
            if (leftEl.classList.contains('is-wrong')) {
                state = ' is-wrong';
            } else if (leftEl.classList.contains('is-right')) {
                state = ' is-right';
            }
            path.setAttribute('class', `aiv-line${state}`);
            path.style.stroke = this.colors.get(left);
            this.svg.appendChild(path);
        });
    }

    /**
     * Complete when every left item is connected.
     *
     * @returns {boolean}
     */
    isComplete() {
        return this.pairs.size === this.root.querySelectorAll('.aiv-match-item[data-side="left"]').length;
    }

    /**
     * Response.
     *
     * @returns {Array}
     */
    response() {
        return Array.from(this.pairs.entries()).map(([key, value]) => ({key, value}));
    }

    /**
     * Marks pairs.
     *
     * @param {Array} results
     */
    showResults(results) {
        results.forEach((r) => {
            this.mark(this.side('left', r.key), !!r.correct);
            this.mark(this.side('right', r.value), !!r.correct);
        });
        this.draw();
    }

    /**
     * Connects a known pair.
     *
     * @param {object} pair
     */
    place(pair) {
        this.connect(pair.key, pair.value);
        [this.side('left', pair.key), this.side('right', pair.value)].forEach((el) => el.classList.add('is-fixed',
            'is-right'));
        this.draw();
    }

    /** Lock and stop observing. */
    lock() {
        super.lock();
        this.draw();
    }
}

/**
 * Spot the mistake.
 */
class SpotMistake extends Base {
    /** Init. */
    init() {
        this.need = this.section.selectcount || 1;
        this.root.addEventListener('click', (e) => {
            const word = e.target.closest('.aiv-word');
            if (!word || this.locked || word.disabled) {
                return;
            }
            const on = !word.classList.contains('is-selected');
            if (on && this.count() >= this.need) {
                if (this.need === 1) {
                    // Single mistake: move the selection.
                    this.root.querySelectorAll('.aiv-word.is-selected').forEach((w) => {
                        w.classList.remove('is-selected', 'is-wrong');
                        w.setAttribute('aria-pressed', 'false');
                    });
                } else {
                    Fx.shake(word);
                    return;
                }
            }
            word.classList.toggle('is-selected', on);
            word.classList.remove('is-right', 'is-wrong');
            word.setAttribute('aria-pressed', on ? 'true' : 'false');
            Sound.play(on ? 'select' : 'back');
            Fx.pop(word, 1.08);
            this.updateCounter();
            this.changed();
        });
        this.updateCounter();
    }

    /**
     * Selected count.
     *
     * @returns {number}
     */
    count() {
        return this.root.querySelectorAll('.aiv-word.is-selected').length;
    }

    /** Updates the counter. */
    updateCounter() {
        const el = this.ctx.card.querySelector('[data-region="selectcount"]');
        if (el) {
            el.textContent = this.ctx.fmt(this.ctx.S.selectedof, {count: this.count(), total: this.need});
        }
    }

    /**
     * Complete when enough words are chosen.
     *
     * @returns {boolean}
     */
    isComplete() {
        return this.count() === this.need;
    }

    /**
     * Response.
     *
     * @returns {Array}
     */
    response() {
        return Array.from(this.root.querySelectorAll('.aiv-word.is-selected'))
            .map((w) => ({key: w.dataset.token, value: '1'}));
    }

    /**
     * Shows the answer: only the mistakes stay selected, with their corrections.
     *
     * @param {Array} list
     */
    solve(list) {
        this.root.querySelectorAll('.aiv-word').forEach((w) => {
            w.classList.remove('is-selected', 'is-wrong');
            w.setAttribute('aria-pressed', 'false');
        });
        super.solve(list);
    }

    /**
     * Marks the chosen words; found mistakes show their correction.
     *
     * @param {Array} results
     */
    showResults(results) {
        results.forEach((r) => {
            const word = this.find(r.key);
            this.mark(word, !!r.correct);
            if (r.correct && word && r.text) {
                word.classList.add('is-corrected');
                word.querySelector('[data-region="fix"]').textContent = r.text;
            }
        });
    }

    /**
     * Hint (eliminate) or answer (mistake with correction).
     *
     * @param {object} pair
     */
    place(pair) {
        const word = this.find(pair.key);
        if (!word) {
            return;
        }
        if (pair.value === 'eliminate') {
            word.classList.remove('is-selected');
            word.classList.add('is-eliminated');
            word.disabled = true;
        } else {
            word.classList.add('is-selected', 'is-right', 'is-fixed', 'is-corrected');
            word.querySelector('[data-region="fix"]').textContent = pair.text || '';
        }
        this.updateCounter();
    }
}

/**
 * Fact or fiction deck.
 */
class Swipe extends Base {
    /** Init. */
    init() {
        this.deck = this.root.querySelector('[data-region="deck"]');
        this.buttons = this.root.querySelector('[data-region="swipebuttons"]');
        this.list = this.root.querySelector('[data-region="verdicts"]');
        this.verdicts = new Map();
        this.cards = Array.from(this.deck.querySelectorAll('.aiv-swcard'));
        this.cards.forEach((c, i) => {
            c.style.zIndex = String(100 - i);
            c.classList.toggle('is-top', i === 0);
        });
        this.buttons.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-verdict]');
            if (btn && !this.locked) {
                this.decide(btn.dataset.verdict);
            }
        });
        this.list.addEventListener('click', (e) => {
            const toggle = e.target.closest('.aiv-verdict-toggle');
            if (!toggle || this.locked || toggle.disabled) {
                return;
            }
            const token = toggle.dataset.token;
            this.setVerdict(token, this.verdicts.get(token) === 'fact' ? 'fiction' : 'fact');
            Sound.play('flip');
            this.clearMarks();
        });
        this.deck.addEventListener('pointerdown', (e) => this.down(e));
        this.deck.tabIndex = 0;
        this.deck.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') {
                this.decide('fiction');
            } else if (e.key === 'ArrowRight') {
                this.decide('fact');
            }
        });
    }

    /**
     * The card on top.
     *
     * @returns {HTMLElement|undefined}
     */
    top() {
        return this.cards.find((c) => !this.verdicts.has(c.dataset.token));
    }

    /**
     * Drag the top card.
     *
     * @param {PointerEvent} e
     */
    down(e) {
        const card = e.target.closest('.aiv-swcard');
        if (!card || card !== this.top() || this.locked) {
            return;
        }
        const x0 = e.clientX;
        card.setPointerCapture(e.pointerId);
        card.classList.add('is-dragging');
        const move = (ev) => {
            const dx = (ev.clientX - x0) / Fx.getScale();
            card.style.transform = `translateX(${dx}px) rotate(${dx / 14}deg)`;
            card.style.setProperty('--fact', String(Math.max(0, Math.min(1, dx / 90))));
            card.style.setProperty('--fiction', String(Math.max(0, Math.min(1, -dx / 90))));
        };
        const up = (ev) => {
            card.removeEventListener('pointermove', move);
            card.removeEventListener('pointerup', up);
            card.removeEventListener('pointercancel', up);
            card.classList.remove('is-dragging');
            const dx = (ev.clientX - x0) / Fx.getScale();
            if (Math.abs(dx) > 90) {
                this.decide(dx > 0 ? 'fact' : 'fiction', dx);
            } else {
                card.style.transform = '';
                card.style.setProperty('--fact', '0');
                card.style.setProperty('--fiction', '0');
            }
        };
        card.addEventListener('pointermove', move);
        card.addEventListener('pointerup', up);
        card.addEventListener('pointercancel', up);
    }

    /**
     * Decides the top card.
     *
     * @param {string} verdict fact|fiction
     * @param {number} dx drag distance
     */
    decide(verdict, dx = 0) {
        const card = this.top();
        if (!card) {
            return;
        }
        this.setVerdict(card.dataset.token, verdict);
        this.fly(card, verdict, dx);
        Sound.play('swipe');
        this.after();
    }

    /**
     * Animates a card off the deck.
     *
     * @param {HTMLElement} card
     * @param {string} verdict
     * @param {number} dx
     */
    fly(card, verdict, dx = 0) {
        const dir = verdict === 'fact' ? 1 : -1;
        card.style.setProperty(verdict === 'fact' ? '--fact' : '--fiction', '1');
        card.classList.add('is-gone');
        if (!Fx.REDUCED && card.animate) {
            card.animate([
                {transform: card.style.transform || `translateX(${dx}px)`},
                {transform: `translateX(${dir * 420}px) rotate(${dir * 24}deg)`, opacity: 0},
            ], {duration: 380, easing: 'cubic-bezier(.2,.7,.3,1)', fill: 'forwards'});
        }
    }

    /**
     * Stores a verdict and updates the list.
     *
     * @param {string} token
     * @param {string} verdict
     */
    setVerdict(token, verdict) {
        this.verdicts.set(token, verdict);
        const row = this.list.querySelector(`.aiv-verdict[data-token="${CSS.escape(token)}"]`);
        const toggle = row.querySelector('.aiv-verdict-toggle');
        toggle.textContent = verdict === 'fact' ? this.ctx.S.fact : this.ctx.S.fiction;
        toggle.classList.toggle('is-fact', verdict === 'fact');
        toggle.classList.toggle('is-fiction', verdict === 'fiction');
        row.classList.remove('is-right', 'is-wrong');
        this.changed();
    }

    /** Moves on to the next card or shows the list. */
    after() {
        const next = this.top();
        this.cards.forEach((c) => c.classList.toggle('is-top', c === next));
        if (!next) {
            window.setTimeout(() => {
                this.deck.hidden = true;
                this.buttons.hidden = true;
                this.list.hidden = false;
                this.list.classList.add('is-in');
            }, Fx.REDUCED ? 0 : 320);
        }
    }

    /**
     * Complete when every statement is decided.
     *
     * @returns {boolean}
     */
    isComplete() {
        return this.verdicts.size === this.cards.length;
    }

    /**
     * Response.
     *
     * @returns {Array}
     */
    response() {
        return Array.from(this.verdicts.entries()).map(([key, value]) => ({key, value}));
    }

    /**
     * Marks rows.
     *
     * @param {Array} results
     */
    showResults(results) {
        results.forEach((r) => this.mark(this.list.querySelector(`.aiv-verdict[data-token="${CSS.escape(r.key)}"]`),
            !!r.correct));
    }

    /**
     * Sets a known verdict.
     *
     * @param {object} pair
     */
    place(pair) {
        const card = this.find(pair.key);
        const wasTop = card === this.top();
        if (card && !this.verdicts.has(pair.key)) {
            this.fly(card, pair.value);
        }
        this.setVerdict(pair.key, pair.value);
        const row = this.list.querySelector(`.aiv-verdict[data-token="${CSS.escape(pair.key)}"]`);
        row.classList.add('is-right', 'is-fixed');
        row.querySelector('.aiv-verdict-toggle').disabled = true;
        if (wasTop || !this.top()) {
            this.after();
        }
    }
}

/**
 * Word builder.
 */
class Unscramble extends Base {
    /** Init. */
    init() {
        this.slots = Array.from(this.root.querySelectorAll('.aiv-slot'));
        this.content = new Map();
        this.root.addEventListener('click', (e) => {
            if (this.locked) {
                return;
            }
            const tile = e.target.closest('.aiv-tile');
            if (tile && !tile.classList.contains('is-used')) {
                const slot = this.slots.find((s) => !this.content.has(s.dataset.key));
                if (slot) {
                    this.put(slot, tile);
                }
                return;
            }
            const slot = e.target.closest('.aiv-slot');
            if (slot && this.content.has(slot.dataset.key) && !slot.classList.contains('is-fixed')) {
                this.take(slot);
                Sound.play('back');
                this.changed();
            }
        });
        this.root.addEventListener('keydown', (e) => {
            if (this.locked || e.key.length !== 1) {
                return;
            }
            const letter = e.key.toUpperCase();
            const tile = Array.from(this.root.querySelectorAll('.aiv-tile:not(.is-used)'))
                .find((t) => t.textContent.trim().toUpperCase() === letter);
            const slot = this.slots.find((s) => !this.content.has(s.dataset.key));
            if (tile && slot) {
                this.put(slot, tile);
            }
        });
    }

    /**
     * Puts a tile into a slot.
     *
     * @param {HTMLElement} slot
     * @param {HTMLElement} tile
     * @param {boolean} fixed
     */
    put(slot, tile, fixed = false) {
        if (this.content.has(slot.dataset.key)) {
            this.take(slot);
        }
        const from = tile.getBoundingClientRect();
        this.content.set(slot.dataset.key, tile.dataset.token);
        const letter = slot.querySelector('.aiv-slot-letter');
        letter.textContent = tile.textContent.trim();
        slot.classList.add('is-filled');
        slot.classList.remove('is-right', 'is-wrong');
        tile.classList.add('is-used');
        Fx.flip(letter, from, 300);
        Sound.play('drop');
        if (fixed) {
            slot.classList.add('is-fixed', 'is-right');
        }
        this.changed();
    }

    /**
     * Returns a slot's letter to the tiles.
     *
     * @param {HTMLElement} slot
     */
    take(slot) {
        const token = this.content.get(slot.dataset.key);
        this.content.delete(slot.dataset.key);
        slot.querySelector('.aiv-slot-letter').textContent = '';
        slot.classList.remove('is-filled', 'is-right', 'is-wrong');
        const tile = this.root.querySelector(`.aiv-tile[data-token="${CSS.escape(token)}"]`);
        if (tile) {
            tile.classList.remove('is-used');
            Fx.pop(tile);
        }
    }

    /**
     * Complete when all slots are filled.
     *
     * @returns {boolean}
     */
    isComplete() {
        return this.content.size === this.slots.length;
    }

    /**
     * Response.
     *
     * @returns {Array}
     */
    response() {
        return this.slots.map((s) => ({key: s.dataset.key, value: this.content.get(s.dataset.key) || ''}))
            .filter((p) => p.value !== '');
    }

    /**
     * Marks slots.
     *
     * @param {Array} results
     */
    showResults(results) {
        results.forEach((r) => this.mark(this.root.querySelector(`.aiv-slot[data-key="${CSS.escape(r.key)}"]`),
            !!r.correct));
    }

    /**
     * Places a known letter.
     *
     * @param {object} pair key slot, value tile token
     */
    place(pair) {
        const slot = this.root.querySelector(`.aiv-slot[data-key="${CSS.escape(pair.key)}"]`);
        let tile = this.root.querySelector(`.aiv-tile[data-token="${CSS.escape(pair.value)}"]`);
        if (!slot || !tile) {
            return;
        }
        // The exact tile may sit elsewhere: use it from there, or any free tile with the same letter.
        if (tile.classList.contains('is-used')) {
            const free = Array.from(this.root.querySelectorAll('.aiv-tile:not(.is-used)'))
                .find((t) => t.textContent.trim() === tile.textContent.trim());
            if (free) {
                tile = free;
            } else {
                this.slots.forEach((s) => {
                    if (this.content.get(s.dataset.key) === pair.value && s !== slot) {
                        this.take(s);
                    }
                });
            }
        }
        this.put(slot, tile, true);
    }
}

const CONTROLLERS = {
    cardselect: CardSelect,
    categorize: Categorize,
    fillblanks: FillBlanks,
    ordering: Ordering,
    matching: Matching,
    spotmistake: SpotMistake,
    swipe: Swipe,
    unscramble: Unscramble,
};

/**
 * Template context for a type.
 *
 * @param {object} section
 * @param {object} S strings
 * @returns {object}
 */
const templateContext = (section, S) => {
    const context = {
        items: numbered(section.items),
        groups: numbered(section.groups),
        segments: section.segments || [],
        total: (section.items || []).length,
        moveup: S.moveup,
        movedown: S.movedown,
    };
    if (section.type === 'unscramble') {
        let k = 0;
        context.words = String(section.wordlengths || '').split(',').filter((x) => x !== '').map((len) => ({
            slots: Array.from({length: parseInt(len, 10)}, () => ({key: `p${k}`, n: ++k})),
        }));
    }
    return context;
};

/**
 * Renders a type into a body element and returns its controller.
 *
 * @param {HTMLElement} body
 * @param {object} section
 * @param {object} ctx onChange, S, fmt, card
 * @returns {Promise<Base>}
 */
export const mount = async(body, section, ctx) => {
    const {html, js} = await Templates.renderForPromise(`mod_aiinteractivevideo/type_${section.type}`,
        templateContext(section, ctx.S));
    Templates.replaceNodeContents(body, html, js);
    const Controller = CONTROLLERS[section.type] || Base;
    return new Controller(body, section, ctx);
};
