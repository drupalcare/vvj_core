/**
 * @file
 * Split the text of an element into word or letter spans for Pattern motion.
 *
 * Each text node becomes a visually hidden copy of its text (so the
 * accessible name, links included, stays whole) plus `aria-hidden` spans:
 * `<span class="vvj-word|vvj-char" style="--vvj-i: n"><span>text</span></span>`.
 * Running it twice on one element changes nothing.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */

((Drupal) => {
  Drupal.Vvj = Drupal.Vvj || {};

  /**
   * Splits the text inside `el` into spans.
   *
   * @param {HTMLElement} el
   *   The element whose text is split (its child elements are kept).
   * @param {{unit?: 'word'|'char'}} [options]
   *   `unit: 'char'` splits letters; the default splits words.
   */
  Drupal.Vvj.splitText = (el, { unit = 'word' } = {}) => {
    if (!el || el.hasAttribute('data-vvj-split')) {
      return;
    }
    el.setAttribute('data-vvj-split', unit);
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) {
      if (walker.currentNode.nodeValue.trim() !== '') {
        nodes.push(walker.currentNode);
      }
    }
    let i = 0;
    nodes.forEach((node) => {
      const text = node.nodeValue;
      const fragment = document.createDocumentFragment();
      const hidden = document.createElement('span');
      hidden.className = 'visually-hidden';
      hidden.textContent = text;
      fragment.appendChild(hidden);
      const parts = unit === 'char' ? Array.from(text) : text.split(/(\s+)/);
      parts.forEach((part) => {
        if (part === '') {
          return;
        }
        if (/^\s+$/.test(part)) {
          const space = document.createElement('span');
          space.setAttribute('aria-hidden', 'true');
          space.textContent = ' ';
          fragment.appendChild(space);
          return;
        }
        const outer = document.createElement('span');
        outer.className = unit === 'char' ? 'vvj-char' : 'vvj-word';
        outer.setAttribute('aria-hidden', 'true');
        outer.style.setProperty('--vvj-i', String(i));
        i += 1;
        const inner = document.createElement('span');
        inner.textContent = part;
        outer.appendChild(inner);
        fragment.appendChild(outer);
      });
      node.replaceWith(fragment);
    });
  };
})(Drupal);
