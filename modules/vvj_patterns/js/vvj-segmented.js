/**
 * @file
 * A segmented control: a group of buttons where exactly one is pressed.
 *
 * Each button carries `aria-pressed`; Left/Right/Home/End move focus
 * between them (Drupal.Vvj.handleArrowKeyNav, roving tabindex) and Enter,
 * Space or a click presses one and calls `onSelect(index, button)`.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

((Drupal) => {
  Drupal.Vvj = Drupal.Vvj || {};

  Drupal.Vvj.Segmented = class VvjSegmented {
    /**
     * @param {HTMLElement} group
     *   The element holding the `<button>`s (give it a role and a label).
     * @param {{onSelect?: function(number, HTMLButtonElement): void,
     *   signal: AbortSignal}} options
     *   The callback and the signal that releases every listener.
     */
    constructor(group, { onSelect = () => {}, signal }) {
      this.group = group;
      this.buttons = Array.from(group.querySelectorAll('button'));
      this.onSelect = onSelect;
      const start = Math.max(
        0,
        this.buttons.findIndex(
          (b) => b.getAttribute('aria-pressed') === 'true',
        ),
      );
      this.buttons.forEach((button, index) => {
        button.setAttribute('aria-pressed', index === start ? 'true' : 'false');
        button.setAttribute('tabindex', index === start ? '0' : '-1');
        button.addEventListener('click', () => this.select(index), { signal });
        button.addEventListener(
          'keydown',
          (event) =>
            Drupal.Vvj.handleArrowKeyNav(event, this.buttons, index, {
              horizontal: true,
            }),
          { signal },
        );
      });
    }

    /**
     * Presses one button and reports it.
     *
     * @param {number} index
     *   The button to press.
     */
    select(index) {
      const target = this.buttons[index];
      if (!target) {
        return;
      }
      this.buttons.forEach((button) => {
        button.setAttribute(
          'aria-pressed',
          button === target ? 'true' : 'false',
        );
        // The roving Tab stop follows the pressed button, for a click too.
        button.setAttribute('tabindex', button === target ? '0' : '-1');
      });
      this.onSelect(index, target);
    }
  };
})(Drupal);
