/**
 * @file
 * The brief panel on the "Build a VVJ view with AI" page: what the chosen format
 * is for, its best practices, and its example prompts one at a time.
 *
 * THE SAME PANEL AS paragraphs_bundles_ai, on the maintainer's instruction of
 * 2026-09-27: "do the same thing as you did with pb". It is copied, not
 * shared — the two projects ship no common code — and renamed: the format
 * select drives it instead of the section select, and it keeps its own
 * remembered switches.
 */

((Drupal, drupalSettings, once) => {
  'use strict';

  /**
   * The best-practice brief, sliding down from the top.
   *
   * THE BAR COMES UP FROM THE BOTTOM AND THIS COMES DOWN FROM THE TOP, so with
   * both open the page is still readable in the band between them. Neither
   * pushes the document: both are fixed to their own edge.
   *
   * THE TEXT IS THE BUNDLE'S OWN DESCRIPTION — one text, two readers. The
   * person reads it here before typing; the model is handed the same words as
   * its instruction. A second field would be a second place to edit.
   *
   * The X is an inline SVG rather than a file, so it renders even where a theme
   * or a CSP blocks the module's own assets. The one control that closes this
   * must never be the thing that fails.
   */
  Drupal.behaviors.vvjAiBrief = {
    attach(context) {
      const selects = once('vvj-ai-brief', '.vvj-ai-panel select[name="format"]', context);

      if (!selects.length) {
        return;
      }

      // ONE KEY PER SWITCH. They are independent — either, both or neither —
      // so a shared key would make hiding the practices also hide the prompts.
      // Both store "off", so an absent value means ON and a first-time visitor
      // sees everything.
      const KEY = 'vvjAiBriefOff';
      const PROMPT_KEY = 'vvjAiPromptsOff';
      const CLOSE = '<svg viewBox="0 -960 960 960" aria-hidden="true">'
        + '<path d="M256-200l-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 '
        + '224 224-56 56-224-224-224 224Z"/></svg>';

      let panel = document.querySelector('.vvj-ai-brief');

      if (!panel) {
        panel = document.createElement('div');
        panel.className = 'vvj-ai-brief';
        // THREE ROWS, AND THE CLOSE BUTTON SITS IN THE FIRST ONE. There is no
        // separate head bar: the name used to live there and is now part of
        // row one, so a head would have shown it twice.
        panel.innerHTML = '<div class="vvj-ai-brief__card">'
          + '<div class="vvj-ai-brief__body">'
          + '<div class="vvj-ai-brief__meta">'
          + '<p class="vvj-ai-brief__about"></p>'
          + '<button type="button" class="vvj-ai-brief__close">' + CLOSE + '</button>'
          + '</div>'
          + '<p class="vvj-ai-brief__heading"></p>'
          + '<ul class="vvj-ai-brief__list"></ul>'
          + '<div class="vvj-ai-brief__prompts"></div>'
          + '</div></div>';
        // IN THE FORM, under the format select — not fixed to the top of the
        // window as in PB. PB's controls sit in a bottom bar; here the form IS
        // the top of the page, and a fixed panel covered the select driving it.
        (selects[0].closest('.form-item') || selects[0]).after(panel);
      }

      const body = panel.querySelector('.vvj-ai-brief__body');
      const about = panel.querySelector('.vvj-ai-brief__about');
      const heading = panel.querySelector('.vvj-ai-brief__heading');
      const list = panel.querySelector('.vvj-ai-brief__list');
      const prompts = panel.querySelector('.vvj-ai-brief__prompts');
      const closeBtn = panel.querySelector('.vvj-ai-brief__close');

      closeBtn.setAttribute('aria-label', Drupal.t('Close'));

      const close = () => panel.classList.remove('vvj-ai-brief--open');

      selects.forEach((select) => {
        const bar = select.closest('.vvj-ai-panel');

        // BOUND BY NAME, NEVER BY CLASS. Both switches carry
        // `.vvj-ai-switch`, so a class lookup returns whichever the form
        // declared first — Prompts — and would silently drive the panel from
        // the wrong control.
        const boxes = {
          practices: bar.querySelector('input[type="checkbox"][name="best_practices"]'),
          prompts: bar.querySelector('input[type="checkbox"][name="prompts"]'),
        };

        // Stored as "off" so the default — no stored value — is ON for both.
        // Somebody who has never built this section is exactly who these are
        // for, and they will not go looking for a switch to turn one on.
        // One key each: they are independent, and sharing a key would make
        // hiding the practices also hide the prompts.
        const restore = (name, key) => {
          const box = boxes[name];

          if (!box) {
            return;
          }

          try {
            box.checked = localStorage.getItem(key) !== '1';
          } catch (error) {
            box.checked = true;
          }
        };

        restore('practices', KEY);
        restore('prompts', PROMPT_KEY);

        const wants = (name) => Boolean(boxes[name] && boxes[name].checked);

        const show = () => {
          const briefs = (drupalSettings.vvjAi || {}).briefs || {};
          const brief = briefs[select.value];

          // EITHER switch keeps the panel open; only both off closes it.
          //
          // CLEARED ON THE WAY OUT, NOT JUST ON THE WAY IN. Returning early
          // without emptying leaves the last section's content inside a closed
          // panel, which then flashes on the next open before the re-render
          // replaces it. Measured 2026-09-20: with both switches off the panel
          // closed correctly and still held three prompt blocks.
          if (!brief || (!wants('practices') && !wants('prompts'))) {
            heading.textContent = '';
            list.textContent = '';
            prompts.textContent = '';
            close();

            return;
          }

          const chosen = select.selectedOptions[0];
          const name = chosen ? chosen.textContent.trim() : '';

          // ROW ONE: category, name, and what it is for — one line, with the
          // close button at its right end.
          //
          // Built as elements and never as innerHTML: this text is a site's
          // own config value, and innerHTML would let whatever is in it run.
          //
          // ⚠ NOT `body.textContent = ''`. The body now HOLDS the three rows,
          // so clearing it would delete the panel's own structure along with
          // the close button. Only the parts that vary are cleared.
          about.textContent = '';

          const facts = [];

          if (brief.category) {
            facts.push([Drupal.t('Category:'), brief.category]);
          }

          if (name) {
            facts.push([Drupal.t('Name:'), name]);
          }

          if (brief.good_for) {
            facts.push([Drupal.t('About:'), brief.good_for]);
          }

          facts.forEach((fact, index) => {
            if (index > 0) {
              const separator = document.createElement('span');
              separator.className = 'vvj-ai-brief__sep';
              // A REAL SPACE EITHER SIDE, not CSS padding. Padding would look
              // right and still read as "structure ·Name:" to a screen reader,
              // which announces the text content and never the box around it.
              separator.textContent = ' · ';
              about.appendChild(separator);
            }

            const key = document.createElement('span');
            key.className = 'vvj-ai-brief__key';
            key.textContent = fact[0];
            about.appendChild(key);
            about.appendChild(document.createTextNode(' ' + fact[1] + ' '));
          });

          // ROWS TWO AND THREE. The first line of the practices is the
          // heading; every line after it is one item in the list.
          //
          // TAKEN BY POSITION, NEVER BY MATCHING THE ENGLISH. Testing for
          // "Best practices…" would work in one language and nowhere else —
          // the same defect removed from the PHP, where warning severity was
          // recovered with str_starts_with($note, 'CAREFUL:').
          // CLEARED FIRST, ALWAYS. Unticking a switch has to empty its section
          // rather than leave the last section's text behind it.
          heading.textContent = '';
          list.textContent = '';

          if (wants('practices')) {
            const lines = String(brief.practices || '')
              .split('\n')
              .map((line) => line.trim())
              .filter((line) => line !== '');

            heading.textContent = lines.length > 0 ? lines[0] : '';

            lines.slice(1).forEach((line) => {
              const item = document.createElement('li');
              item.textContent = line;
              list.appendChild(item);
            });
          }

          // ROW FOUR: the example prompts, each with a button that puts it in
          // the box. A person who has never built this section gets a sentence
          // to edit rather than an empty field and a placeholder naming fields.
          //
          // WHAT IS TRANSLATED HERE AND WHAT IS NOT. The button and the
          // variant heading are interface, so they go through Drupal.t(). The
          // prompt TEXT is the maintainer's content and must not: it is
          // translated in config, through the schema, like the practices above
          // it. Passing it through Drupal.t() would freeze it in English.
          prompts.textContent = '';

          const examples = (wants('prompts') ? (brief.prompts || []) : [])
            .filter((example) => example && example.text);

          // ONE PROMPT AT A TIME, NOT ALL OF THEM.
          //
          // MEASURED 2026-09-20 on Slideshow, the only bundle with a named set
          // so far: its three variants made the prompts 359px of a 532px body
          // inside a card capped at 448px, so 113px sat out of sight. The card
          // was NOT overflowing and `.vvj-ai-brief__body` scrolls correctly —
          // the defect was that scrolling happened at all, against "the user
          // will see all data and they will not scroll".
          //
          // Switching between them costs a 28px strip and shows one ~126px
          // block, so the prompts drop from 359px to about 154px and the panel
          // keeps roughly 105px spare — enough that the longest practices list
          // still fits beside them. It also stops the panel growing as more
          // bundles gain named sets during the prompt writing.
          const holder = document.createElement('div');
          holder.className = 'vvj-ai-brief__prompt-holder';

          const switches = [];

          const draw = (index) => {
            const example = examples[index];

            if (!example) {
              return;
            }

            holder.textContent = '';

            switches.forEach((button, position) => {
              button.setAttribute('aria-pressed', position === index ? 'true' : 'false');
            });

            const block = document.createElement('div');
            block.className = 'vvj-ai-brief__prompt';

            // THE NAME ONLY WHEN NOTHING ELSE CARRIES IT. With switches above,
            // the pressed one already says which variant this is, and saying it
            // twice costs a line the panel does not have.
            if (example.name && examples.length < 2) {
              const label = document.createElement('p');
              label.className = 'vvj-ai-brief__prompt-name';
              // The variant's own word — "images", "hero" — announced as what
              // this example is FOR, not as a bare label.
              label.textContent = Drupal.t('Example — @variant', { '@variant': example.name });
              block.appendChild(label);
            }

            const text = document.createElement('pre');
            text.className = 'vvj-ai-brief__prompt-text';
            text.textContent = example.text;
            block.appendChild(text);

            const use = document.createElement('button');
            use.type = 'button';
            use.className = 'vvj-ai-brief__use';
            use.textContent = Drupal.t('Use this prompt');

            use.addEventListener('click', () => {
              const words = bar.querySelector('textarea[name="words"]');

              if (!words) {
                return;
              }

              words.value = example.text;
              // The change event matters: anything listening for the person
              // typing must see this the same way, or a filled box can still
              // behave as an empty one.
              words.dispatchEvent(new Event('input', { bubbles: true }));

              // THE ROW FOLLOWS THE PROMPT. A "View mode" prompt asks for
              // teasers; leaving "Show each item as" on Fields would send the
              // model a request the settings contradict.
              const showAs = bar.querySelector('select[name="show_as"]');
              const mode = example.name === 'View mode' ? 'teaser' : 'fields';

              if (showAs && showAs.querySelector('option[value="' + mode + '"]')) {
                showAs.value = mode;
                showAs.dispatchEvent(new Event('change', { bubbles: true }));
              }

              words.focus();
              close();
            });

            block.appendChild(use);
            holder.appendChild(block);
          };

          // BUTTONS, NOT TABS. A real tablist owes the person arrow-key
          // movement and a roving tabindex; a pressed-state button group owes
          // nothing beyond what a button already does, and this is a group of
          // two or three. `aria-pressed` is what announces which is showing.
          if (examples.length > 1) {
            const strip = document.createElement('div');
            strip.className = 'vvj-ai-brief__variants';
            strip.setAttribute('role', 'group');
            strip.setAttribute('aria-label', Drupal.t('Choose an example'));

            examples.forEach((example, index) => {
              const choice = document.createElement('button');
              choice.type = 'button';
              choice.className = 'vvj-ai-brief__variant';
              // THE CONFIG VALUE, NOT Drupal.t(). The variant names are the
              // maintainer's content and are translated in config through the
              // schema, like the practices; passing one through Drupal.t()
              // would freeze it in English. Only the numbered fallback, which
              // this file invents, is interface text.
              choice.textContent = example.name || Drupal.t('Example @number', { '@number': index + 1 });
              choice.setAttribute('aria-pressed', 'false');
              choice.addEventListener('click', () => draw(index));
              switches.push(choice);
              strip.appendChild(choice);
            });

            prompts.appendChild(strip);
          }

          if (examples.length > 0) {
            prompts.appendChild(holder);
            draw(0);
          }

          body.scrollTop = 0;
          panel.classList.add('vvj-ai-brief--open');
        };

        select.addEventListener('change', show);

        // ALWAYS show(), NEVER a close() branch here. With one switch, "off"
        // meant "close the panel". With two it does not: unticking Prompts
        // while Best practices is still on has to leave the panel open with
        // one section fewer. show() already decides that — it closes only when
        // BOTH are off — so the listener's whole job is to remember the choice
        // and re-render.
        const remember = (name, key) => {
          const box = boxes[name];

          if (!box) {
            return;
          }

          box.addEventListener('change', () => {
            try {
              localStorage.setItem(key, box.checked ? '0' : '1');
            } catch (error) {
              // A private window, or storage switched off. The switch still
              // works; it just will not be remembered.
            }

            show();
          });
        };

        remember('practices', KEY);
        remember('prompts', PROMPT_KEY);
      });

      closeBtn.addEventListener('click', close);

      // Escape closes it only from the panel or the select that opened it;
      // elsewhere on the page Escape belongs to whatever has focus.
      document.addEventListener('keydown', (event) => {
        const from = event.target;

        if (event.key === 'Escape' && from instanceof Node
          && (panel.contains(from) || selects.includes(from))) {
          close();
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
