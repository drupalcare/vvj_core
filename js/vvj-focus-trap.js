/**
 * @file
 * Focus trap helper for modal-like VVJ patterns.
 *
 * Used by lightbox-style components that need to keep keyboard focus
 * inside a modal element while it's open. The native `<dialog>`
 * element handles this automatically when opened with `.showModal()`,
 * so prefer `<dialog>` where possible. This helper exists for patterns
 * that can't use `<dialog>` (e.g., custom-element-only modal layers).
 * Shared via the `Drupal.Vvj` namespace (not ES module import/export);
 * consumers declare `dependencies: [vvj_core/focus-trap]` and call:
 *
 *   const release = Drupal.Vvj.trapFocus(modalElement, signal);
 *   // ... when modal closes:
 *   release();
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

// phpcs:disable Drupal.NamingConventions.UpperCaseConstant
// phpcs:disable Generic.PHP.UpperCaseConstant
// phpcs:disable Squiz.WhiteSpace.OperatorSpacing

((Drupal) => {
  'use strict';

  Drupal.Vvj = Drupal.Vvj || {};

  const FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
    'audio[controls]',
    'video[controls]',
    'summary',
  ].join(', ');

  /**
   * Constrain Tab navigation to descendants of `container`.
   *
   * @param {HTMLElement} container
   *   The element to trap focus within.
   * @param {AbortSignal} signal
   *   Aborting this signal removes the keydown listener.
   * @returns {() => void}
   *   Call to release the trap explicitly (in addition to aborting the signal).
   */
  Drupal.Vvj.trapFocus = (container, signal) => {
    const previouslyFocused = /** @type {HTMLElement|null} */ (document.activeElement);

    const focusables = container.querySelectorAll(FOCUSABLE_SELECTOR);
    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    // Move initial focus into the container.
    first?.focus();

    const onKeyDown = (event) => {
      if (event.key !== 'Tab') { return;
      }
      if (focusables.length === 0) {
        event.preventDefault();
        return;
      }
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    container.addEventListener('keydown', onKeyDown, { signal });

    return () => {
      previouslyFocused?.focus();
    };
  };

})(Drupal);
