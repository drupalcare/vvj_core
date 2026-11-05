/**
 * @file
 * Follow the active item of a VVJ element from a Pattern script.
 *
 * The element writes the state through ElementBase.writeState() (vvj_core):
 * `data-vvj-index`, `--vvj-total` and a `vvj:change` event. This file only
 * reads that contract; it never writes state of its own.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

((Drupal) => {
  Drupal.Vvj = Drupal.Vvj || {};

  /**
   * Reads the state the element last wrote, or null before the first write.
   *
   * @param {HTMLElement} root
   *   The VVJ element carrying the `vvj-pattern` class.
   * @return {{index: number, total: number}|null}
   *   The current state.
   */
  const readState = (root) => {
    if (root.dataset.vvjIndex === undefined) {
      return null;
    }
    const index = parseInt(root.dataset.vvjIndex, 10);
    const total = parseInt(root.style.getPropertyValue('--vvj-total'), 10);
    if (Number.isNaN(index)) {
      return null;
    }
    return { index, total: Number.isNaN(total) ? 0 : total };
  };

  /**
   * Calls `cb({ index, total })` now (when known) and on every change.
   *
   * @param {HTMLElement} root
   *   The VVJ element carrying the `vvj-pattern` class.
   * @param {(state: {index: number, total: number}) => void} cb
   *   Called once per distinct index.
   * @param {AbortSignal} signal
   *   Stops following when aborted.
   */
  Drupal.Vvj.onState = (root, cb, signal) => {
    let last = null;
    const deliver = (state) => {
      if (!state || state.index === last) {
        return;
      }
      last = state.index;
      cb(state);
    };
    document.addEventListener(
      'vvj:change',
      (event) => {
        if (event.detail?.source === root) {
          deliver({ index: event.detail.index, total: event.detail.total });
        }
      },
      { signal },
    );
    // State written before this script attached (or at hydration).
    deliver(readState(root));
    Drupal.Vvj.whenReady(root, () => deliver(readState(root)), signal);
  };

  /**
   * Formats a 1-based position for a visual counter.
   *
   * @param {number} n
   *   The 1-based position.
   * @param {number} total
   *   The number of items.
   * @param {{pad?: boolean}} [options]
   *   `pad: true` zero-pads to the width of `total`, at least two digits.
   * @return {string}
   *   The formatted number, for example "03".
   */
  Drupal.Vvj.formatCount = (n, total, { pad = false } = {}) => {
    const text = String(n);
    if (!pad) {
      return text;
    }
    return text.padStart(Math.max(2, String(total).length), '0');
  };
})(Drupal);
