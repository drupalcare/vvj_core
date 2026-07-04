/**
 * @file
 * VvjElementBase — Custom Element base class for every VVJ pattern.
 *
 * Shared via the `Drupal.Vvj` namespace (Drupal core's cross-file sharing
 * pattern — cf. `Drupal.Ajax`), NOT ES module import/export. Pattern
 * modules declare `dependencies: [vvj_core/element-base]` in their
 * `*.libraries.yml` so this file loads first, then extend the class:
 *
 *   ((Drupal) => {
 *     const { ElementBase } = Drupal.Vvj;
 *     class VvjaAccordionElement extends ElementBase {
 *       static get patternSlug() { return 'vvja'; }
 *       onHydrate() { ... uses this.signal for AbortController listeners ... }
 *     }
 *     customElements.define('vvja-accordion', VvjaAccordionElement);
 *   })(Drupal);
 *
 * Why namespace + dependency ordering instead of native ES modules:
 * Drupal has no first-class cross-module ES `import` resolution yet (the
 * core import-map API, issue #3398525, is unmerged). Core itself ships
 * zero `type: module` / `import` in hand-authored JS. This is the pattern
 * that survives subdirectory installs, JS aggregation, and every Drupal
 * version — no paths, no import maps, no build step.
 *
 * What this base provides:
 *   - Lazy hydration via IntersectionObserver (lighthouse-100 by default).
 *     The element doesn't initialize event listeners or observers until
 *     it scrolls into view (200px rootMargin). Add `data-eager` attribute
 *     to force immediate hydration.
 *   - AbortController-tracked listeners. Subclasses pass `{ signal: this.signal }`
 *     to every addEventListener; disconnectedCallback aborts the controller
 *     and every listener cleans up at once.
 *   - `prefers-reduced-motion` awareness via `this.reducedMotion` getter.
 *   - View Transitions helper: `this.withTransition(cb)` wraps `cb` in
 *     `document.startViewTransition()` when supported, falls back to plain
 *     callback otherwise. Reduced-motion users skip transitions.
 *   - `vvj:*` CustomEvent dispatch via `this.emit(name, detail)` — bubbles
 *     on document so any consumer can listen cross-instance, cross-module
 *     without polluting globals.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

// phpcs:disable Drupal.NamingConventions.UpperCaseConstant
// phpcs:disable Generic.PHP.UpperCaseConstant
// phpcs:disable Squiz.WhiteSpace.OperatorSpacing

((Drupal) => {
  'use strict';

  Drupal.Vvj = Drupal.Vvj || {};

  Drupal.Vvj.ElementBase = class VvjElementBase extends HTMLElement {

    /**
     * The pattern's slug — must be declared by every subclass.
     * Examples: 'vvja', 'vvjb', 'vvjs', 'vvjt'.
     *
     * @returns {string}
     */
    static get patternSlug() {
      throw new Error('VvjElementBase subclass must declare static patternSlug.');
    }

    /**
     * Subclasses can extend this list with attributes that should
     * trigger reactive behavior. Default is empty.
     *
     * @returns {string[]}
     */
    static get observedAttributes() {
      return [];
    }

    constructor() {
      super();
      /** @type {AbortController|null} */
      this._abortController = null;
      /** @type {boolean} */
      this._isHydrated = false;
      /** @type {IntersectionObserver|null} */
      this._observer = null;
    }

    // ---------------------------------------------------------------
    // Lifecycle.
    // ---------------------------------------------------------------

    connectedCallback() {
      if (this._isHydrated) {
        return;
      }

      if (this.hasAttribute('data-eager')) {
        this._hydrate();
        return;
      }

      // Lazy: wait until the element is visible (or close to) before
      // doing any work. Saves render budget on long Views pages.
      this._observer = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
          this._hydrate();
          this._observer?.disconnect();
          this._observer = null;
        }
      }, { rootMargin: '200px' });
      this._observer.observe(this);
    }

    disconnectedCallback() {
      this._abortController?.abort();
      this._observer?.disconnect();
      this._abortController = null;
      this._observer = null;
      this._isHydrated = false;
      this.onDisconnect?.();
    }

    // ---------------------------------------------------------------
    // Subclass override surface.
    // ---------------------------------------------------------------

    /**
     * Called once when the element first becomes visible (or eagerly when
     * `data-eager` is present). Subclasses set up listeners + initial
     * state here. All listeners MUST use `{ signal: this.signal }` so
     * `disconnectedCallback()` cleans them up.
     *
     * Default no-op so empty subclasses still work.
     */
    onHydrate() {
      // Subclasses implement.
    }

    /**
     * Optional cleanup hook beyond the AbortController. Subclasses
     * override if they hold non-listener resources (timers, RAFs).
     */
    onDisconnect() {
      // Subclasses implement.
    }

    // ---------------------------------------------------------------
    // Helpers for subclasses.
    // ---------------------------------------------------------------

    /**
     * AbortSignal that will fire on `disconnectedCallback()`.
     * Pass to every `addEventListener` as `{ signal: this.signal }`.
     *
     * @returns {AbortSignal}
     */
    get signal() {
      if (!this._abortController) {
        this._abortController = new AbortController();
      }
      return this._abortController.signal;
    }

    /**
     * `true` when `prefers-reduced-motion: reduce` is set.
     * Subclasses check this before triggering any animation/transition.
     *
     * @returns {boolean}
     */
    get reducedMotion() {
      return matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    /**
     * Wrap a state change in `document.startViewTransition()` when the
     * browser supports it AND the user hasn't requested reduced motion.
     * Otherwise call `cb` directly.
     *
     * @param {() => void|Promise<void>} cb
     *   The state mutation to perform.
     * @returns {ViewTransition|null}
     *   The ViewTransition if one was started, null otherwise.
     */
    withTransition(cb) {
      if (
        typeof document.startViewTransition === 'function'
        && !this.reducedMotion
      ) {
        return document.startViewTransition(cb);
      }
      cb();
      return null;
    }

    /**
     * Dispatch a `vvj:<name>` CustomEvent on `document`.
     * Detail always includes `source: this` so consumers can identify
     * the originating instance.
     *
     * @param {string} name
     *   Event name (without the `vvj:` prefix).
     * @param {Record<string, unknown>} [detail={}]
     *   Additional payload.
     */
    emit(name, detail = {}) {
      document.dispatchEvent(new CustomEvent(`vvj:${name}`, {
        bubbles: true,
        detail: { source: this, ...detail },
      }));
    }

    // ---------------------------------------------------------------
    // Internal.
    // ---------------------------------------------------------------

    _hydrate() {
      if (this._isHydrated) {
        return;
      }
      this._isHydrated = true;
      this._abortController = new AbortController();
      try {
        this.onHydrate();
        this.emit('ready');
      }
      catch (err) {
        const tag = this.tagName.toLowerCase();
        console.error('[vvj] hydration failed for <' + tag + '>', err);
      }
    }

  };

})(Drupal);
