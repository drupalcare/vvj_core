# VVJ motion and script rules: the avoid list

Every VVJ module (vvj_core, vvja, vvjb, vvjc, vvjf, vvjh, vvjl, vvjp, vvjr, vvjs, vvjt) follows these rules.
`scripts/check-vvj-motion.py` enforces rules 1, 3 (as a warning), 6, 7 and 11; it names the rule number in each
finding. Run it with no arguments from a lane that holds all eleven repos; `--self-test` proves each check can go red.

Why this list exists: buttons and cards in most VVJ patterns shook when the cursor sat near their edge. A hover
rule moved the hovered element; the element left the cursor, hover dropped, it moved back, hover started again.

## 1. A hover never moves its own box

Why: the moved box leaves the cursor at its edge, and hover flickers on and off (the shake).
Move a child, a pseudo-element, or hover a stable parent. A growing zoom (scale of 1 or more) is safe: the bigger
box still covers the cursor. When the hovered box has no stable wrapper (a print card, a pill button), keep the
motion and add a hit-area guard: a `:hover::after` with a negative inset at least as large as the move.

```css
/* Bad */
.card-inner:hover { transform: rotateY(180deg); }
/* Good: hover the stable card, flip the inner */
.card:hover > .card-inner { transform: rotateY(180deg); }
/* Good when there is no wrapper: guard the hit area */
.btn:hover { transform: translateY(-2px); }
.btn:hover::after { content: ""; position: absolute; inset: -4px; }
```

The same applies to JS hover listeners: listen on the stable outer element, never on the box that turns.

## 2. JS sets classes; CSS animates

Why: inline style animation in JS fights the cascade, skips reduced-motion rules and cannot be themed.

```js
// Bad
el.style.transform = 'translateX(' + x + 'px)';
// Good
el.classList.add('is-active'); // CSS: .is-active { transform: ...; transition: ...; }
```

## 3. Batch layout reads, then writes, in one animation frame

Why: a read after a write forces a synchronous layout on every pass of the loop (layout thrash).

```js
// Bad
items.forEach((el) => { el.style.height = el.offsetWidth + 'px'; });
// Good
const widths = items.map((el) => el.offsetWidth);
requestAnimationFrame(() => items.forEach((el, i) => { el.style.setProperty('--w', widths[i] + 'px'); }));
```

## 4. Throttle pointer, scroll and resize work to one run per frame; prefer ResizeObserver

Why: these events fire many times per frame; doing work on each one janks the page.

```js
// Bad
window.addEventListener('resize', layout);
// Good
const ro = new ResizeObserver(() => requestAnimationFrame(layout));
ro.observe(root);
```

## 5. No bare mouseenter / mouseleave toggles

Why: a toggle with no delay flickers when the cursor crosses a gap or an edge.

```js
// Bad
item.addEventListener('mouseleave', close);
// Good
item.addEventListener('mouseleave', () => { timer = setTimeout(close, 150); });
item.addEventListener('mouseenter', () => clearTimeout(timer));
```

## 6. Every timer, listener and observer is removed in detach

Why: Ajax and Layout Builder re-attach behaviors; anything left behind runs twice and leaks.

```js
// Bad
attach() { document.addEventListener('keydown', onKey); }
// Good
attach() { controller = new AbortController(); document.addEventListener('keydown', onKey, { signal: controller.signal }); },
detach() { controller.abort(); }
```

The checker matches removeEventListener / clearInterval / disconnect by name; a listener added with an
AbortController `signal` should also keep a removeEventListener-free detach that calls `abort()` (needs a manual
read: the checker cannot see signals).

## 7. Drupal.behaviors with once()

Why: attach runs again on every Ajax response; without once() each element is bound again.

```js
// Bad
attach(context) { context.querySelectorAll('.x').forEach(init); }
// Good
attach(context) { once('vvj-x', '.x', context).forEach(init); }
```

## 8. prefers-reduced-motion stops autoplay and transitions

Why: motion can make some visitors ill; the OS setting must be honoured.

```css
/* Good */
@media (prefers-reduced-motion: reduce) { .x { transition: none; animation: none; } }
```
```js
// Good
if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { autoplay = false; }
```

## 9. Reserve space before JS runs

Why: content that jumps when the script loads moves the click target and harms layout stability.

```css
/* Bad: height only known after JS */
/* Good */
.vvj-stage { aspect-ratio: 16 / 9; min-height: 20rem; }
```

## 10. Keyboard: arrows only inside the focused widget; Escape always works

Why: arrow keys on the document steal page scrolling; a dialog or open panel must close with Escape.

```js
// Bad
document.addEventListener('keydown', (e) => { if (e.key === 'ArrowRight') next(); });
// Good
widget.addEventListener('keydown', (e) => {
  if (e.key === 'ArrowRight') next();
  if (e.key === 'Escape') close();
});
```

## 11. A button that works in hover mode and click mode has one class per mode

Why: when a pattern can switch between hover and click (vvjf flip trigger, vvjr reveal method), a rule or listener
that reaches the button in both modes makes hover and click act together: hovering reveals, the click then hides.
The mode sets the class (server side in Twig when the template knows it, or from the root's data attribute when JS
builds the button). Hover-mode rules select only `--hover`; click-mode rules and handlers select only `--click`.
In hover mode a mouse click on the button does nothing; keyboard (event.detail 0) and touch still use it.

```twig
{# Good #}
<button class="vvjr-toggle vvjr-toggle--{{ reveal_method == 'hover' ? 'hover' : 'click' }}">
```
```css
/* Bad: reaches both modes */
.vvjr-toggle:hover { transform: scale(1.08); }
/* Good */
.vvjr-toggle--click:hover { transform: scale(1.08); }
```
```js
// Bad
item.querySelector('.vvjr-toggle').addEventListener('click', toggle);
// Good
item.querySelector('.vvjr-toggle--click')?.addEventListener('click', toggle);
```

The mode buttons the checker knows are listed in `MODE_BUTTONS` in `scripts/check-vvj-motion.py`; add a new one there.
