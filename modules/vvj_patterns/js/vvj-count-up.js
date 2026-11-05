/**
 * @file
 * Count a number up from zero once, when it scrolls into view.
 *
 * The final number stays in the markup, so with JS off, with reduced motion
 * or without IntersectionObserver it simply shows. While counting, the
 * moving digits are `aria-hidden` and a visually hidden copy keeps the real
 * value as the accessible text.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

((Drupal) => {
  Drupal.Vvj = Drupal.Vvj || {};

  /**
   * Counts the integer in `el` up from zero.
   *
   * @param {HTMLElement} el
   *   An element whose whole text is an integer (for example "214").
   * @param {{signal?: AbortSignal, duration?: number}} [options]
   *   The signal stops the count and the observer; duration in ms.
   */
  Drupal.Vvj.countUp = (el, { signal, duration = 1400 } = {}) => {
    if (!el || el.hasAttribute('data-vvj-count')) {
      return;
    }
    const text = el.textContent.trim();
    if (!/^\d+$/.test(text)) {
      return;
    }
    const to = parseInt(text, 10);
    el.setAttribute('data-vvj-count', String(to));
    if (Drupal.Vvj.reducedMotion() || !('IntersectionObserver' in window)) {
      return;
    }
    const digits = document.createElement('span');
    digits.setAttribute('aria-hidden', 'true');
    digits.textContent = '0';
    const real = document.createElement('span');
    real.className = 'visually-hidden';
    real.textContent = text;
    el.replaceChildren(digits, real);

    let frame = 0;
    const observer = new IntersectionObserver(
      (entries) => {
        if (!entries[0].isIntersecting) {
          return;
        }
        observer.disconnect();
        const start = performance.now();
        const step = (now) => {
          const p = Math.min((now - start) / duration, 1);
          digits.textContent = String(Math.round((1 - (1 - p) ** 4) * to));
          if (p < 1) {
            frame = requestAnimationFrame(step);
          }
        };
        frame = requestAnimationFrame(step);
      },
      { threshold: 0.4 },
    );
    observer.observe(el);
    if (signal) {
      signal.addEventListener(
        'abort',
        () => {
          observer.disconnect();
          cancelAnimationFrame(frame);
          el.textContent = text;
          el.removeAttribute('data-vvj-count');
        },
        { once: true },
      );
    }
  };
})(Drupal);
