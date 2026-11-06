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
 *   - `this.hydrated` and `Drupal.Vvj.whenReady(el, cb, signal)` so a script
 *     attached after hydration still runs its setup once.
 *   - `this.writeState({ index, total })`: the one shared "which item is
 *     active" contract (`data-vvj-index`, `--vvj-index`, `--vvj-total`,
 *     `--vvj-progress`, per item `--vvj-i`, and a `vvj:change` event). It
 *     does nothing unless the element carries the `vvj-pattern` class, so a
 *     view without a Pattern renders and behaves exactly as before.
 *   - Static `Drupal.Vvj.reducedMotion()` and `Drupal.Vvj.withTransition(cb)`
 *     for scripts that are not elements; the instance members delegate.
 *   - `Drupal.Vvj.ownWidth(el)` and `Drupal.Vvj.watchOwnWidth(el, signal,
 *     onChange)`: the width a Pattern compares its breakpoint against (its
 *     own content box), and a debounced ResizeObserver on it.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

// phpcs:disable Drupal.NamingConventions.UpperCaseConstant
// phpcs:disable Generic.PHP.UpperCaseConstant
// phpcs:disable Squiz.WhiteSpace.OperatorSpacing

((Drupal) => {
  Drupal.Vvj = Drupal.Vvj || {};

  /**
   * `true` when the user asked for reduced motion.
   *
   * @return {boolean}
   *   Whether `prefers-reduced-motion: reduce` matches.
   */
  Drupal.Vvj.reducedMotion = () =>
    matchMedia('(prefers-reduced-motion: reduce)').matches;

  /**
   * Runs `cb` inside a view transition when one can run, else directly.
   *
   * NOT WHILE THE PAGE IS HIDDEN. The browser rejects a view transition in a
   * hidden document ("Transition was aborted because of invalid state.
   * Document hidden"), and nothing catches that rejection. The state change
   * still happens; there is simply nobody to animate it for.
   *
   * @param {() => void|Promise<void>} cb
   *   The state mutation to perform.
   * @return {ViewTransition|null}
   *   The ViewTransition if one was started, null otherwise.
   */
  Drupal.Vvj.withTransition = (cb) => {
    if (
      typeof document.startViewTransition === 'function' &&
      !Drupal.Vvj.reducedMotion() &&
      !document.hidden
    ) {
      const transition = document.startViewTransition(cb);
      // A quick second click starts a new transition, and the browser skips
      // the running one by rejecting its `ready` promise ("Transition was
      // skipped"). Only the animation is dropped; the state change still
      // ran. Errors thrown by `cb` still surface through `finished`.
      transition.ready.catch(() => {});
      return transition;
    }
    cb();
    return null;
  };

  /**
   * Calls `cb(el)` once the VVJ element has hydrated.
   *
   * Elements hydrate lazily and fire `vvj:ready` once; a script attached
   * later would miss that event, so this checks `el.hydrated` first.
   *
   * @param {HTMLElement} el
   *   A VVJ custom element.
   * @param {(el: HTMLElement) => void} cb
   *   Called once, with the element.
   * @param {AbortSignal} [signal]
   *   Cancels the wait.
   */
  Drupal.Vvj.whenReady = (el, cb, signal) => {
    if (signal?.aborted) {
      return;
    }
    if (el.hydrated) {
      cb(el);
      return;
    }
    const onReady = (event) => {
      if (event.detail?.source !== el) {
        return;
      }
      document.removeEventListener('vvj:ready', onReady);
      cb(el);
    };
    document.addEventListener('vvj:ready', onReady, { signal });
  };

  /**
   * The width a Pattern compares its breakpoint against: its own room.
   *
   * The element is the `vvj-region` size container, so this is the same
   * content-box width the Pattern's `@container` rules see. A root that is
   * not laid out yet (inside a closed tab, say) measures 0; the window
   * width stands in until the observer reports a real size.
   *
   * @param {HTMLElement} el
   *   The Pattern root.
   *
   * @return {number}
   *   The content-box inline size in CSS pixels.
   */
  Drupal.Vvj.ownWidth = (el) => {
    const style = getComputedStyle(el);
    const width =
      el.clientWidth -
      (parseFloat(style.paddingLeft) || 0) -
      (parseFloat(style.paddingRight) || 0);
    return width > 0 ? width : window.innerWidth;
  };

  /**
   * Calls `onChange(width)` after the element's own width settles.
   *
   * Debounced, and a width of 0 (the root stopped being laid out) is
   * ignored so a hidden Pattern keeps its last state. The observer is
   * disconnected when `signal` aborts (the element left the page).
   *
   * @param {HTMLElement} el
   *   The Pattern root.
   * @param {AbortSignal} signal
   *   The element's lifetime signal.
   * @param {function(number): void} onChange
   *   Receives the new content-box inline size.
   */
  Drupal.Vvj.watchOwnWidth = (el, signal, onChange) => {
    if (!('ResizeObserver' in window) || signal.aborted) {
      return;
    }
    let timer = 0;
    const observer = new ResizeObserver((entries) => {
      const width = entries[entries.length - 1].contentRect.width;
      if (width <= 0) {
        return;
      }
      clearTimeout(timer);
      timer = setTimeout(() => onChange(width), 100);
    });
    observer.observe(el);
    signal.addEventListener(
      'abort',
      () => {
        clearTimeout(timer);
        observer.disconnect();
      },
      { once: true },
    );
  };

  Drupal.Vvj.ElementBase = class VvjElementBase extends HTMLElement {
    /**
     * The pattern's slug — must be declared by every subclass.
     * Examples: 'vvja', 'vvjb', 'vvjs', 'vvjt'.
     *
     * @return {string}
     */
    static get patternSlug() {
      throw new Error(
        'VvjElementBase subclass must declare static patternSlug.',
      );
    }

    /**
     * Subclasses can extend this list with attributes that should
     * trigger reactive behavior. Default is empty.
     *
     * @return {string[]}
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
      /** @type {number|null} */
      this._vvjStateIndex = null;
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
      this._observer = new IntersectionObserver(
        ([entry]) => {
          if (entry.isIntersecting) {
            this._hydrate();
            this._observer?.disconnect();
            this._observer = null;
          }
        },
        { rootMargin: '200px' },
      );
      this._observer.observe(this);
    }

    disconnectedCallback() {
      this._abortController?.abort();
      this._observer?.disconnect();
      this._abortController = null;
      this._observer = null;
      this._isHydrated = false;
      this._vvjStateIndex = null;
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
     * @return {AbortSignal}
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
     * @return {boolean}
     */
    get reducedMotion() {
      return Drupal.Vvj.reducedMotion();
    }

    /**
     * `true` once `onHydrate()` has run (and until disconnect).
     *
     * @return {boolean}
     */
    get hydrated() {
      return this._isHydrated;
    }

    /**
     * Wrap a state change in `document.startViewTransition()` when the
     * browser supports it AND the user hasn't requested reduced motion.
     * Otherwise call `cb` directly.
     *
     * @param {() => void|Promise<void>} cb
     *   The state mutation to perform.
     * @return {ViewTransition|null}
     *   The ViewTransition if one was started, null otherwise.
     */
    withTransition(cb) {
      return Drupal.Vvj.withTransition(cb);
    }

    /**
     * Writes the active item for Pattern CSS and Pattern scripts.
     *
     * Only on elements with the `vvj-pattern` class; anything else returns
     * at once, so a view without a Pattern is untouched. Writes
     * `data-vvj-index` and `--vvj-index` (0-based), `--vvj-total`,
     * `--vvj-progress` (0 to 1; defaults to (index + 1) / total) and, when
     * `items` is given, `--vvj-i` on each item. Emits `vvj:change`
     * `{ index, total }` once per change of index.
     *
     * @param {{index: number, total: number, progress?: number,
     *   items?: ArrayLike<HTMLElement>}} state
     *   The state to write.
     */
    writeState({ index, total, progress, items }) {
      if (!this.classList.contains('vvj-pattern') || index < 0) {
        return;
      }
      let value = 0;
      if (typeof progress === 'number') {
        value = progress;
      } else if (total > 0) {
        value = (index + 1) / total;
      }
      this.dataset.vvjIndex = String(index);
      this.style.setProperty('--vvj-index', String(index));
      this.style.setProperty('--vvj-total', String(total));
      this.style.setProperty(
        '--vvj-progress',
        String(Math.min(1, Math.max(0, value))),
      );
      if (items) {
        Array.from(items).forEach((item, i) => {
          item.style.setProperty('--vvj-i', String(i));
        });
      }
      if (this._vvjStateIndex !== index) {
        this._vvjStateIndex = index;
        this.emit('change', { index, total });
      }
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
      document.dispatchEvent(
        new CustomEvent(`vvj:${name}`, {
          bubbles: true,
          detail: { source: this, ...detail },
        }),
      );
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
      } catch (err) {
        const tag = this.tagName.toLowerCase();
        console.error(`[vvj] hydration failed for <${tag}>`, err);
      }
    }
  };
})(Drupal);
