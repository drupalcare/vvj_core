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

  // A letter of a right-to-left script, and the first letter of any script.
  const RTL_LETTER =
    /[\p{Script=Arabic}\p{Script=Hebrew}\p{Script=Syriac}\p{Script=Thaana}\p{Script=Nko}]/u;
  const FIRST_LETTER = /\p{L}/u;

  /**
   * Splits the text inside `el` into spans.
   *
   * @param {HTMLElement} el
   *   The element whose text is split (its child elements are kept).
   * @param {{unit?: 'word'|'char'}} [options]
   *   `unit: 'char'` splits letters; the default splits words.
   */
  Drupal.Vvj.splitText = (el, { unit: asked = 'word' } = {}) => {
    if (!el || el.hasAttribute('data-vvj-split')) {
      return;
    }
    const all = el.textContent;
    // Arabic and Hebrew letters join to their neighbors; one span per letter
    // would print them unjoined, so right-to-left text splits by word only.
    const unit = asked === 'char' && RTL_LETTER.test(all) ? 'word' : asked;
    el.setAttribute('data-vvj-split', unit);
    // Split pieces are boxes, and boxes run in the page's direction, so
    // English split on an Arabic page printed its words (and letters) back
    // to front. The pieces follow the text's own direction instead; the
    // element keeps the side it was aligned to.
    const first = all.match(FIRST_LETTER);
    if (first) {
      const textDir = RTL_LETTER.test(first[0]) ? 'rtl' : 'ltr';
      const style = getComputedStyle(el);
      if (style.direction !== textDir) {
        const start = style.direction === 'rtl' ? 'right' : 'left';
        const end = start === 'right' ? 'left' : 'right';
        const align = { start, end }[style.textAlign];
        if (align) {
          el.style.textAlign = align;
        }
        // A flex row lines its pieces up by direction too: keep them on the
        // page's start side.
        if (
          /flex$/.test(style.display) &&
          ['normal', 'start', 'flex-start'].includes(style.justifyContent)
        ) {
          el.style.justifyContent = 'flex-end';
        }
        el.setAttribute('dir', textDir);
      }
    }
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
