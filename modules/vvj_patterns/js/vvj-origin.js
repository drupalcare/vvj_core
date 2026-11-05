/**
 * @file
 * Pointer or control origin for a Pattern (PLAN-js section 2, helper 9).
 *
 * Writes `--vvj-origin-x` and `--vvj-origin-y` (px, relative to the matched
 * element) on the element matching `selector` that the pointer enters or
 * presses, or, for Enter and Space, at the centre of the focused control.
 * CSS reads them, for example as the centre of a clip-path circle.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

((Drupal) => {
  Drupal.Vvj = Drupal.Vvj || {};

  /**
   * Starts tracking; stops when `signal` aborts.
   *
   * @param {HTMLElement} root
   *   The Pattern element.
   * @param {string} selector
   *   The elements that receive the origin, for example '.reveal-item'.
   * @param {{signal?: AbortSignal}} [options]
   *   Listener options.
   */
  Drupal.Vvj.trackOrigin = (root, selector, { signal } = {}) => {
    const write = (el, x, y) => {
      el.style.setProperty('--vvj-origin-x', `${Math.round(x)}px`);
      el.style.setProperty('--vvj-origin-y', `${Math.round(y)}px`);
    };
    const match = (target) => {
      if (!(target instanceof Element)) {
        return null;
      }
      const el = target.closest(selector);
      return el && root.contains(el) ? el : null;
    };
    const fromPointer = (event) => {
      const el = match(event.target);
      if (!el) {
        return;
      }
      // Only the moment the pointer enters the element, not moves inside it.
      if (
        event.type === 'pointerover' &&
        event.relatedTarget instanceof Node &&
        el.contains(event.relatedTarget)
      ) {
        return;
      }
      const box = el.getBoundingClientRect();
      write(el, event.clientX - box.left, event.clientY - box.top);
    };
    const fromKey = (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') {
        return;
      }
      const el = match(event.target);
      if (!el) {
        return;
      }
      const box = el.getBoundingClientRect();
      const control = event.target.getBoundingClientRect();
      write(
        el,
        control.left + control.width / 2 - box.left,
        control.top + control.height / 2 - box.top,
      );
    };
    const options = { capture: true, signal };
    root.addEventListener('pointerover', fromPointer, options);
    root.addEventListener('pointerdown', fromPointer, options);
    root.addEventListener('keydown', fromKey, options);
  };
})(Drupal);
