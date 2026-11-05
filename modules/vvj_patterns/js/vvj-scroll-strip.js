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
