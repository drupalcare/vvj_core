/**
 * @file
 * Deep-link URL-hash bridge for VVJ patterns.
 *
 * Wires up the URL hash to a VVJ instance for shareable
 * `#<slug>-<id>-<n>` fragments — opening / activating panel N when a
 * matching hash is in the URL on load or via hashchange. Shared via the
 * `Drupal.Vvj` namespace (not ES module import/export); consumers declare
 * `dependencies: [vvj_core/deeplink-bridge]` and call:
 *
 *   Drupal.Vvj.wireDeeplink(this, 'accordion', 'faqs', this.signal,
 *     (panelIndex) => { this.openPanel(panelIndex); });
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
   * @param {HTMLElement} element
   *   The custom element / container.
   * @param {string} prefix
   *   The hash prefix word (e.g., 'accordion', 'slideshow', 'tab').
   * @param {string} identifier
   *   The site-builder-chosen identifier (e.g., 'faqs').
   * @param {AbortSignal} signal
   *   Aborting removes the hashchange listener.
   * @param {(panelIndex: number) => void} onMatch
   *   Callback invoked with the 1-based panel index when the hash matches.
   */
  Drupal.Vvj.wireDeeplink = (element, prefix, identifier, signal, onMatch) => {
    if (!identifier) { return;
    }
    const hashPrefix = `#${prefix}-${identifier}-`;

    const tryOpenFromHash = () => {
      const hash = window.location.hash;
      if (!hash || !hash.startsWith(hashPrefix)) { return;
      }
      const panelNumber = Number.parseInt(hash.slice(hashPrefix.length), 10);
      if (Number.isNaN(panelNumber) || panelNumber < 1) { return;
      }
      onMatch(panelNumber);
    };

    // Initial check (after a tick so the element is fully attached).
    Promise.resolve().then(tryOpenFromHash);

    window.addEventListener('hashchange', tryOpenFromHash, { signal });
  };

  /**
   * Update the URL hash to reflect an open panel — without scroll jump.
   *
   * @param {string} prefix
   * @param {string} identifier
   * @param {number} panelIndex
   *   1-based.
   */
  Drupal.Vvj.writeDeeplinkHash = (prefix, identifier, panelIndex) => {
    if (!identifier) { return;
    }
    const newHash = `#${prefix}-${identifier}-${panelIndex}`;
    if (window.location.hash !== newHash) {
      window.history.replaceState(null, '', newHash);
    }
  };

})(Drupal);
