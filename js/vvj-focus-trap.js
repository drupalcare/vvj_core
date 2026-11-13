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
 *   const release = Drupal.Vvj.trapFocus(modalElement, signal, {
 *     returnFocus: openerButton,
 *   });
 *   // ... when modal closes (removes the listener, focuses the opener):
 *   release();
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

// phpcs:disable Drupal.NamingConventions.UpperCaseConstant
// phpcs:disable Generic.PHP.UpperCaseConstant
// phpcs:disable Squiz.WhiteSpace.OperatorSpacing

((Drupal) => {
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
   * Whether a matched element can really take focus right now.
   *
   * A hidden, inert or not-rendered element ignores `.focus()`, so the trap
   * must never pick one as its first or last stop, or Tab escapes the modal.
   *
   * @param {HTMLElement} el
   *   An element matched by FOCUSABLE_SELECTOR.
   * @return {boolean}
   *   TRUE when it is in the Tab order, rendered, and not inside a hidden or
   *   inert subtree.
   */
  const canTakeFocus = (el) => {
    // A button or link with tabindex="-1" (a roving-tabindex group) matches
    // the selector but is not a Tab stop; as the last match it would let
    // Tab leave the modal.
    if (el.getAttribute('tabindex') === '-1') {
      return false;
    }
    if (el.closest('[inert], [hidden]')) {
      return false;
    }
    if (typeof el.checkVisibility === 'function') {
      return el.checkVisibility({ visibilityProperty: true });
    }
    return el.getClientRects().length > 0;
  };

  /**
   * The focusable descendants of `container`, read fresh.
   *
   * @param {HTMLElement} container
   *   The trapping element.
   * @return {HTMLElement[]}
   *   Focusable, visible descendants in document order.
   */
  const focusablesIn = (container) =>
    Array.from(container.querySelectorAll(FOCUSABLE_SELECTOR)).filter(
      canTakeFocus,
    );

  /**
   * Constrain Tab navigation to descendants of `container`.
   *
   * The focusable list is read on every Tab, so content that changes inside
   * the modal (a new slide, a new image) never leaves stale first/last stops.
   *
   * @param {HTMLElement} container
   *   The element to trap focus within.
   * @param {AbortSignal} signal
   *   Aborting this signal removes the keydown listener.
   * @param {{returnFocus?: HTMLElement|null}} [options]
   *   `returnFocus`: the element that opened the modal. Pass it whenever the
   *   caller moves focus before calling this, or `release()` cannot know the
   *   opener. Defaults to the element focused when this is called.
   * @return {() => void}
   *   Call to release the trap: removes its listener and returns focus to the
   *   opener. Calling it more than once does nothing.
   */
  Drupal.Vvj.trapFocus = (container, signal, options = {}) => {
    const opener =
      options.returnFocus ??
      /** @type {HTMLElement|null} */ (document.activeElement);

    // An inner controller, so release() removes this trap's own listener
    // even while the caller's signal stays alive (a modal opened again adds
    // a new listener; the old one must be gone).
    const controller = new AbortController();
    const abortTrap = () => controller.abort();
    if (signal?.aborted) {
      controller.abort();
    } else {
      signal?.addEventListener('abort', abortTrap, { once: true });
    }

    // Move initial focus into the container.
    focusablesIn(container)[0]?.focus();

    const onKeyDown = (event) => {
      if (event.key !== 'Tab') {
        return;
      }
      const focusables = focusablesIn(container);
      if (focusables.length === 0) {
        event.preventDefault();
        return;
      }
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      const active = document.activeElement;
      // The container itself (tabindex="-1") counts as before the first stop.
      const atStart = active === first || active === container;
      if (event.shiftKey && atStart) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    };

    container.addEventListener('keydown', onKeyDown, {
      signal: controller.signal,
    });

    let released = false;
    return () => {
      if (released) {
        return;
      }
      released = true;
      controller.abort();
      signal?.removeEventListener('abort', abortTrap);
      if (
        opener instanceof HTMLElement &&
        opener.isConnected &&
        !container.contains(opener)
      ) {
        opener.focus();
      }
    };
  };
})(Drupal);
