/**
 * @file
 * A horizontal scroll strip with edge state and previous/next buttons.
 *
 * Root classes, for Pattern CSS:
 * - `vvj-strip--ready`: the strip is wired (scroll buttons can work).
 * - `vvj-strip--at-start` / `vvj-strip--at-end`: scrolled to that edge.
 * - `vvj-strip--static`: nothing to scroll.
 * The buttons get `aria-disabled="true"` at their edge.
 *
 * Only the strip scrolls, never the window: scrollToItem() moves the strip's
 * own scroll position, unlike scrollIntoView(), which also scrolls every
 * scrollable ancestor and can jump the page.
 *
 * Swipe: when a Pattern's CSS gives the strip `overflow-x: hidden` (or
 * `clip`), the strip is not a user scroller, so a sideways touch or pen
 * swipe of 40px or more pages it like the buttons (RTL-aware, reduced motion
 * respected through scrollByPage()). The Pattern also sets
 * `touch-action: pan-y pinch-zoom` on the strip so vertical swipes still
 * scroll the page. A strip with `overflow-x: auto` keeps the browser's own
 * scrolling and gets no swipe handling. The click that ends a swipe is
 * swallowed, so a swipe never activates an item.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

((Drupal) => {
  Drupal.Vvj = Drupal.Vvj || {};

  Drupal.Vvj.ScrollStrip = class VvjScrollStrip {
    /**
     * @param {HTMLElement} strip
     *   The element that scrolls horizontally.
     * @param {{prev?: HTMLElement|null, next?: HTMLElement|null,
     *   root?: HTMLElement, signal: AbortSignal}} options
     *   The buttons, the element that gets the state classes (default: the
     *   strip), and the signal that releases every listener and observer.
     */
    constructor(strip, { prev = null, next = null, root = strip, signal }) {
      this.strip = strip;
      this.prev = prev;
      this.next = next;
      this.root = root;
      this.update = this.update.bind(this);

      if (prev) {
        prev.addEventListener('click', () => this.scrollByPage(-1), {
          signal,
        });
      }
      if (next) {
        next.addEventListener('click', () => this.scrollByPage(1), {
          signal,
        });
      }
      strip.addEventListener('scroll', this.update, { passive: true, signal });
      this.listenForSwipe(signal);
      if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(this.update);
        observer.observe(strip);
        signal.addEventListener('abort', () => observer.disconnect(), {
          once: true,
        });
      } else {
        window.addEventListener('resize', Drupal.debounce(this.update, 100), {
          signal,
        });
      }
      signal.addEventListener(
        'abort',
        () => {
          root.classList.remove(
            'vvj-strip--ready',
            'vvj-strip--at-start',
            'vvj-strip--at-end',
            'vvj-strip--static',
          );
        },
        { once: true },
      );

      root.classList.add('vvj-strip--ready');
      this.update();
    }

    /**
     * `-1` in a right-to-left strip, else `1`.
     *
     * @return {number}
     */
    get direction() {
      return getComputedStyle(this.strip).direction === 'rtl' ? -1 : 1;
    }

    /**
     * Whether the strip is closed to user scrolling (overflow-x hidden or clip).
     *
     * @return {boolean}
     */
    get swipeable() {
      const { overflowX } = getComputedStyle(this.strip);
      return overflowX === 'hidden' || overflowX === 'clip';
    }

    /**
     * Pages the strip on a sideways touch or pen swipe, see the file docblock.
     *
     * @param {AbortSignal} signal
     *   Releases the listeners.
     */
    listenForSwipe(signal) {
      const { strip } = this;
      let start = null;
      strip.addEventListener(
        'pointerdown',
        (event) => {
          // Read per gesture: a Pattern may switch overflow in a media query.
          start =
            event.pointerType === 'mouse' || !this.swipeable
              ? null
              : { x: event.clientX, y: event.clientY };
        },
        { passive: true, signal },
      );
      strip.addEventListener(
        'pointercancel',
        () => {
          start = null;
        },
        { signal },
      );
      strip.addEventListener(
        'pointerup',
        (event) => {
          if (!start) {
            return;
          }
          const dx = event.clientX - start.x;
          const dy = event.clientY - start.y;
          start = null;
          if (Math.abs(dx) < 40 || Math.abs(dx) <= Math.abs(dy)) {
            return;
          }
          // A leftward swipe moves towards the end in LTR, the start in RTL.
          const rtl = this.direction === -1;
          this.scrollByPage((dx < 0) !== rtl ? 1 : -1);
          const swallow = (click) => {
            click.preventDefault();
            click.stopPropagation();
          };
          strip.addEventListener('click', swallow, {
            capture: true,
            once: true,
            signal,
          });
          // No click follows some swipes; never eat a later, real click.
          setTimeout(
            () =>
              strip.removeEventListener('click', swallow, { capture: true }),
            400,
          );
        },
        { signal },
      );
    }

    /**
     * Re-reads the scroll position and writes the edge state.
     */
    update() {
      const { strip, root } = this;
      const max = strip.scrollWidth - strip.clientWidth;
      // scrollLeft is 0 at the start and negative towards the end in RTL.
      const offset = Math.abs(strip.scrollLeft);
      const atStart = offset <= 1;
      const atEnd = offset >= max - 1;
      root.classList.toggle('vvj-strip--static', max <= 1);
      root.classList.toggle('vvj-strip--at-start', atStart);
      root.classList.toggle('vvj-strip--at-end', atEnd);
      if (this.prev) {
        this.prev.setAttribute('aria-disabled', atStart ? 'true' : 'false');
      }
      if (this.next) {
        this.next.setAttribute('aria-disabled', atEnd ? 'true' : 'false');
      }
    }

    /**
     * Scrolls by most of one visible width.
     *
     * @param {number} step
     *   `1` towards the end, `-1` towards the start.
     */
    scrollByPage(step) {
      this.strip.scrollBy({
        left: step * this.direction * this.strip.clientWidth * 0.8,
        behavior: Drupal.Vvj.reducedMotion() ? 'instant' : 'smooth',
      });
    }

    /**
     * Brings item `index` into view inside the strip (nearest edge).
     *
     * @param {number} index
     *   0-based index among the strip's element children, or among
     *   `items` when given.
     * @param {ArrayLike<HTMLElement>} [items]
     *   The items; defaults to the strip's element children.
     */
    scrollToItem(index, items) {
      const list = items || this.strip.children;
      const item = list[index];
      if (!item) {
        return;
      }
      const box = this.strip.getBoundingClientRect();
      const rect = item.getBoundingClientRect();
      let delta = 0;
      if (rect.left < box.left) {
        delta = rect.left - box.left;
      } else if (rect.right > box.right) {
        delta = rect.right - box.right;
      }
      if (Math.abs(delta) < 1) {
        return;
      }
      this.strip.scrollBy({
        left: delta,
        behavior: Drupal.Vvj.reducedMotion() ? 'instant' : 'smooth',
      });
    }
  };
})(Drupal);
