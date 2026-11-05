/**
 * @file
 * VVJ Patterns Demo: lets the demo view's stage reach both viewport edges.
 *
 * It only writes classes and custom properties on the view's own wrapper
 * (.vvj-demo-stage). The width is the viewport without its scrollbar
 * (documentElement.clientWidth), so the stage never causes sideways scroll.
 */

((Drupal, once) => {
  /**
   * Measures where the stage sits in the column and stretches it.
   *
   * @param {HTMLElement} stage
   *   The view wrapper carrying .vvj-demo-stage.
   */
  function fit(stage) {
    const root = document.documentElement;
    // Measure the stage where the theme puts it, not where it was moved.
    stage.classList.remove('vvj-demo-stage--bleed');
    const rect = stage.getBoundingClientRect();
    const width = root.clientWidth;
    const rtl = getComputedStyle(stage).direction === 'rtl';
    const offset = rtl ? width - rect.right : rect.left + window.scrollX;

    stage.style.setProperty('--vvj-demo-stage-width', `${width}px`);
    stage.style.setProperty(
      '--vvj-demo-stage-offset',
      `${Math.max(0, Math.round(offset))}px`,
    );
    stage.classList.add('vvj-demo-stage--bleed');
  }

  Drupal.behaviors.vvjDemoStage = {
    attach(context) {
      once('vvj-demo-stage', '.vvj-demo-stage', context).forEach((stage) => {
        let frame = 0;
        const schedule = () => {
          cancelAnimationFrame(frame);
          frame = requestAnimationFrame(() => fit(stage));
        };
        fit(stage);
        // The viewport, the scrollbar appearing, and the column changing all
        // resize one of these two boxes.
        if ('ResizeObserver' in window) {
          const observer = new ResizeObserver(schedule);
          observer.observe(document.documentElement);
          if (stage.parentElement) {
            observer.observe(stage.parentElement);
          }
        } else {
          window.addEventListener('resize', schedule);
        }
      });
    },
  };
})(Drupal, once);
