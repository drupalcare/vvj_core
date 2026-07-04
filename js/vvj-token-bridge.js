/**
 * @file
 * Drupal.behaviors → custom-element bridge.
 *
 * Pattern modules ship a Drupal.behaviors entry that just confirms
 * presence — actual hydration happens via the custom element's own
 * connectedCallback + IntersectionObserver. This file provides a
 * one-line registration helper so each pattern's vvjX.js stays small.
 * Shared via the `Drupal.Vvj` namespace (not ES module import/export);
 * consumers declare `dependencies: [vvj_core/token-bridge]` and call:
 *
 *   // vvja/js/vvja.js
 *   Drupal.Vvj.registerBehavior('VVJAccordion', '.vvja');
 *
 * That's the entire bridge. Custom element does the rest.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

// phpcs:disable Drupal.NamingConventions.UpperCaseConstant
// phpcs:disable Generic.PHP.UpperCaseConstant
// phpcs:disable Squiz.WhiteSpace.OperatorSpacing

((Drupal, once) => {
  'use strict';

  Drupal.Vvj = Drupal.Vvj || {};

  /**
   * Register a no-op Drupal.behaviors entry for a VVJ pattern.
   *
   * The behavior exists for two reasons:
   *   1. Backwards compat with v1 — sites can keep referring to
   *      `Drupal.behaviors.<name>` from custom code.
   *   2. Drupal core attaches behaviors after AJAX swaps; this is the
   *      moment to ensure freshly-injected custom elements have been
   *      noticed by the browser. (No-op when none are present.)
   *
   * @param {string} behaviorKey
   *   The legacy behavior key (e.g., 'VVJAccordion', 'VVJBCarousel').
   * @param {string} selector
   *   CSS selector for instances of this pattern (e.g., '.vvja').
   */
  Drupal.Vvj.registerBehavior = (behaviorKey, selector) => {
    Drupal.behaviors[behaviorKey] = {
      attach(context) {
        // Marker only — actual hydration happens via the custom element's
        // own connectedCallback. once() prevents repeated attach calls
        // from running side-effects.
        once(`vvj-${behaviorKey}`, selector, context);
      },
      detach() {
        // Custom element's disconnectedCallback aborts every listener
        // it set up. Nothing for the behavior to clean up.
      },
    };
  };

})(Drupal, once);
