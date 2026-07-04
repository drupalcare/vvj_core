/**
 * @file
 * Shared keyboard navigation primitives for VVJ patterns.
 *
 * Used by accordion / tabs / carousels — anywhere a list of focusable
 * triggers needs Up/Down/Home/End navigation per W3C ARIA APG. Shared via
 * the `Drupal.Vvj` namespace (not ES module import/export); consumers
 * declare `dependencies: [vvj_core/keyboard-nav]` and call:
 *
 *   triggers.forEach((t, i) => {
 *     t.addEventListener('keydown',
 *       (e) => Drupal.Vvj.handleArrowKeyNav(e, triggers, i), { signal });
 *   });
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

// phpcs:disable Drupal.NamingConventions.UpperCaseConstant
// phpcs:disable Generic.PHP.UpperCaseConstant
// phpcs:disable Squiz.WhiteSpace.OperatorSpacing

((Drupal) => {
  'use strict';

  Drupal.Vvj = Drupal.Vvj || {};

  /**
   * Move focus between siblings in a NodeList based on keyboard input.
   *
   * @param {KeyboardEvent} event
   * @param {NodeListOf<HTMLElement>|HTMLElement[]} items
   *   Focusable items in document order.
   * @param {number} currentIndex
   *   The index of the currently-focused item.
   * @param {{horizontal?: boolean}} [options]
   *   Pass `{ horizontal: true }` to use Left/Right instead of Up/Down
   *   (e.g., for horizontal tab strips).
   */
  Drupal.Vvj.handleArrowKeyNav = (event, items, currentIndex, options = {}) => {
    const { horizontal = false } = options;
    const PREV_KEY = horizontal ? 'ArrowLeft' : 'ArrowUp';
    const NEXT_KEY = horizontal ? 'ArrowRight' : 'ArrowDown';

    let targetIndex;
    switch (event.key) {
      case NEXT_KEY:
        targetIndex = (currentIndex + 1) % items.length;
        break;

      case PREV_KEY:
        targetIndex = (currentIndex - 1 + items.length) % items.length;
        break;

      case 'Home':
        targetIndex = 0;
        break;

      case 'End':
        targetIndex = items.length - 1;
        break;

      default:
        return;
    }

    // Roving tabindex pattern: only the focused item is tab-reachable.
    items.forEach((item) => item.setAttribute('tabindex', '-1'));
    const target = items[targetIndex];
    target.setAttribute('tabindex', '0');
    target.focus();
    event.preventDefault();
  };

})(Drupal);
