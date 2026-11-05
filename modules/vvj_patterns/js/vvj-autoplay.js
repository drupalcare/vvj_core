/**
 * @file
 * Autoplay for a Pattern: a timer, a visible Pause / Play button, a hold
 * while the pointer or focus is inside, a full stop when the visitor picks
 * an item, and a pause while the page is hidden. Reduced motion starts it
 * stopped (WCAG 2.2.2).
 *
 * The root gets `vvj-autoplay--playing` while it runs and
 * `vvj-autoplay--held` while it waits, and `--vvj-autoplay-duration`, so a
 * Pattern can draw its timer in CSS. The button gets `aria-pressed="true"`
 * while stopped and its `.vvj-autoplay-label` reads "Pause" or "Play".
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

((Drupal) => {
  Drupal.Vvj = Drupal.Vvj || {};

  Drupal.Vvj.Autoplay = class VvjAutoplay {
    /**
     * @param {HTMLElement} root
     *   The element whose hover and focus hold the timer.
     * @param {{duration: number, button?: HTMLButtonElement|null,
     *   onTick: function(): void, signal: AbortSignal}} options
     *   Milliseconds per item, the Pause / Play button, the step callback and
     *   the signal that stops everything.
     */
    constructor(root, { duration, button = null, onTick, signal }) {
      this.root = root;
      this.duration = Math.max(1000, duration || 7000);
      this.button = button;
      this.onTick = onTick;
      this.stopped = Drupal.Vvj.reducedMotion();
      this.held = false;
      this.timer = 0;
      this.remaining = this.duration;
      this.startedAt = 0;
      root.style.setProperty('--vvj-autoplay-duration', `${this.duration}ms`);

      const hold = (value) => {
        this.held = value;
        this.render();
      };
      root.addEventListener('pointerenter', () => hold(true), { signal });
      root.addEventListener('pointerleave', () => hold(false), { signal });
      root.addEventListener(
        'focusin',
        (event) => hold(event.target !== this.button),
        { signal },
      );
      root.addEventListener(
        'focusout',
        (event) => {
          if (!root.contains(/** @type {Node|null} */ (event.relatedTarget))) {
            hold(false);
          }
        },
        { signal },
      );
      document.addEventListener('visibilitychange', () => this.render(), {
        signal,
      });
      if (button) {
        button.addEventListener(
          'click',
          () => (this.stopped ? this.play() : this.stop()),
          { signal },
        );
      }
      signal.addEventListener('abort', () => clearTimeout(this.timer), {
        once: true,
      });
      this.render();
    }

    /** Starts again from a full interval. */
    restart() {
      this.remaining = this.duration;
      this.render();
    }

    /** Stops for good, until the button is pressed. */
    stop() {
      this.stopped = true;
      this.render();
    }

    /** Plays from a full interval. */
    play() {
      this.stopped = false;
      this.restart();
    }

    /** Applies the state: classes, button, timer. */
    render() {
      const waiting = this.held || document.hidden;
      const running = !this.stopped && !waiting;
      clearTimeout(this.timer);
      if (this.startedAt) {
        this.remaining = Math.max(
          0,
          this.remaining - (Date.now() - this.startedAt),
        );
        this.startedAt = 0;
      }
      this.root.classList.toggle('vvj-autoplay--playing', !this.stopped);
      this.root.classList.toggle(
        'vvj-autoplay--held',
        !this.stopped && waiting,
      );
      if (this.button) {
        this.button.setAttribute('aria-pressed', String(this.stopped));
        const label = this.button.querySelector('.vvj-autoplay-label');
        if (label) {
          label.textContent = this.stopped ? Drupal.t('Play') : Drupal.t('Pause');
        }
      }
      if (!running) {
        return;
      }
      this.startedAt = Date.now();
      this.timer = setTimeout(() => {
        this.startedAt = 0;
        this.remaining = this.duration;
        this.onTick();
        this.render();
      }, this.remaining || this.duration);
    }
  };
})(Drupal);
