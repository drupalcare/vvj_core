/**
 * @file
 * VVJ Patterns Demo: lets the demo view's stage reach both viewport edges.
 *
 * The stage breaks out of the content column only when nothing sits beside
 * that column (no sidebar, no block next to the view). That is decided from
 * the page's layout, measured, not from any theme's class names, so it works
 * the same in Olivero, Solo, the W3CSS themes and any other theme. When
 * something sits beside it, the stage stays in the column and is never moved,
 * so it cannot cover a sidebar and causes no layout shift.
 *
 * It only writes classes and custom properties on the view's own wrapper
 * (.vvj-demo-stage). The width is the viewport without its scrollbar
 * (documentElement.clientWidth), so the stage never causes sideways scroll.
 */

((Drupal, once) => {
  /**
   * Allowed rounding, in pixels, when comparing edges and widths.
   *
   * @type {number}
   */
  const TOLERANCE = 2;

  /**
   * Tells whether an element takes part in the page's normal flow layout.
   *
   * Fixed and absolutely positioned elements (off-canvas menus, the W3CSS
   * vertical nav, overlays) and hidden ones do not form a column.
   *
   * @param {Element} element
   *   The element to test.
   * @param {DOMRect} rect
   *   Its bounding box.
   *
   * @return {boolean}
   *   TRUE when the element occupies space in the flow.
   */
  function inFlow(element, rect) {
    if (rect.width < 1 || rect.height < 1) {
      return false;
    }
    const style = getComputedStyle(element);
    return (
      style.position !== 'fixed' &&
      style.position !== 'absolute' &&
      style.visibility !== 'hidden'
    );
  }

  /**
   * Tells whether anything sits beside the stage's column.
   *
   * Walks from the stage up to <body>. At each level, a sibling that shares
   * vertical space with the current box but none of its horizontal space is a
   * column next to it: a sidebar, whatever the theme calls it. A sidebar that
   * the theme stacks under the content (narrow screens) shares no vertical
   * space, so it does not count.
   *
   * @param {HTMLElement} stage
   *   The view wrapper, measured where the theme puts it.
   *
   * @return {boolean}
   *   TRUE when a column sits beside the stage.
   */
  function hasNeighborColumn(stage) {
    let node = stage;
    while (node && node !== document.body && node.parentElement) {
      const box = node.getBoundingClientRect();
      const siblings = node.parentElement.children;
      for (let i = 0; i < siblings.length; i++) {
        const sibling = siblings[i];
        if (sibling !== node) {
          const rect = sibling.getBoundingClientRect();
          const sharesRow =
            rect.top < box.bottom - TOLERANCE &&
            rect.bottom > box.top + TOLERANCE;
          const beside =
            rect.right <= box.left + TOLERANCE ||
            rect.left >= box.right - TOLERANCE;
          if (sharesRow && beside && inFlow(sibling, rect)) {
            return true;
          }
        }
      }
      node = node.parentElement;
    }
    return false;
  }

  /**
   * Decides whether the stage breaks out, and if so stretches it.
   *
   * @param {HTMLElement} stage
   *   The view wrapper carrying .vvj-demo-stage.
   */
  function fit(stage) {
    const root = document.documentElement;
    const wasBleeding = stage.classList.contains('vvj-demo-stage--bleed');
    // Measure the stage where the theme puts it, not where it was moved.
    // Removing and re-adding the class happens inside one task, before the
    // browser paints, so it is not a visible shift.
    stage.classList.remove('vvj-demo-stage--bleed');
    const rect = stage.getBoundingClientRect();
    // Drupal's displace (the admin toolbar and sidebar) publishes the room it
    // takes at each edge; the stage bleeds to those edges, never under them.
    const rootStyle = getComputedStyle(root);
    const displaceStart =
      parseFloat(rootStyle.getPropertyValue('--drupal-displace-offset-left')) ||
      0;
    const displaceEnd =
      parseFloat(
        rootStyle.getPropertyValue('--drupal-displace-offset-right'),
      ) || 0;
    const width = root.clientWidth - displaceStart - displaceEnd;

    // Already as wide as the viewport (a full-width theme region), or a
    // column beside it: stay in the column, nothing to move.
    if (rect.width >= width - TOLERANCE || hasNeighborColumn(stage)) {
      if (wasBleeding) {
        stage.style.removeProperty('--vvj-demo-stage-width');
        stage.style.removeProperty('--vvj-demo-stage-offset');
      }
      return;
    }

    const rtl = getComputedStyle(stage).direction === 'rtl';
    const offset = rtl
      ? root.clientWidth - displaceEnd - rect.right
      : rect.left + window.scrollX - displaceStart;

    stage.style.setProperty('--vvj-demo-stage-width', `${width}px`);
    stage.style.setProperty(
      '--vvj-demo-stage-offset',
      `${Math.max(0, Math.round(offset))}px`,
    );
    stage.classList.add('vvj-demo-stage--bleed');
  }

  /**
   * One AbortController per wired stage, aborted when the stage is unloaded.
   *
   * @type {WeakMap<HTMLElement, AbortController>}
   */
  const controllers = new WeakMap();

  Drupal.behaviors.vvjDemoStage = {
    attach(context) {
      once('vvj-demo-stage', '.vvj-demo-stage', context).forEach((stage) => {
        const controller = new AbortController();
        const { signal } = controller;
        controllers.set(stage, controller);
        let frame = 0;
        let lastViewport = document.documentElement.clientWidth;
        let lastColumn = stage.parentElement
          ? stage.parentElement.clientWidth
          : 0;
        // Refit only when a WIDTH changed (viewport, scrollbar, the column).
        // Height changes (images loading inside the stage grow its parent)
        // cannot change the decision or the offset, and refitting on them
        // would only cost layout work.
        const schedule = () => {
          const viewport = document.documentElement.clientWidth;
          const column = stage.parentElement
            ? stage.parentElement.clientWidth
            : 0;
          if (viewport === lastViewport && column === lastColumn) {
            return;
          }
          lastViewport = viewport;
          lastColumn = column;
          cancelAnimationFrame(frame);
          frame = requestAnimationFrame(() => fit(stage));
        };
        fit(stage);
        if ('ResizeObserver' in window) {
          const observer = new ResizeObserver(schedule);
          observer.observe(document.documentElement);
          if (stage.parentElement) {
            observer.observe(stage.parentElement);
          }
          signal.addEventListener('abort', () => observer.disconnect(), {
            once: true,
          });
        } else {
          window.addEventListener('resize', schedule, { signal });
        }
        // The admin toolbar and sidebar change the displace offsets without
        // changing any width the observer watches. Drupal announces that with
        // a jQuery-only event, but always writes the offsets to the root's
        // inline style, so watch that and refit when they move.
        const displaceKey = () => {
          const style = document.documentElement.style;
          return `${style.getPropertyValue('--drupal-displace-offset-left')}|${style.getPropertyValue('--drupal-displace-offset-right')}`;
        };
        let lastDisplace = displaceKey();
        const displaceObserver = new MutationObserver(() => {
          const key = displaceKey();
          if (key === lastDisplace) {
            return;
          }
          lastDisplace = key;
          cancelAnimationFrame(frame);
          frame = requestAnimationFrame(() => fit(stage));
        });
        displaceObserver.observe(document.documentElement, {
          attributes: true,
          attributeFilter: ['style'],
        });
        signal.addEventListener('abort', () => displaceObserver.disconnect(), {
          once: true,
        });
        signal.addEventListener('abort', () => cancelAnimationFrame(frame), {
          once: true,
        });
      });
    },

    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      once
        .remove('vvj-demo-stage', '.vvj-demo-stage', context)
        .forEach((stage) => {
          controllers.get(stage)?.abort();
          controllers.delete(stage);
        });
    },
  };
})(Drupal, once);
