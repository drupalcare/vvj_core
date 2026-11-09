/**
 * @file
 * Real-page control/text audit (owner 2026-11-08: "fix anything like that").
 *
 * For every top-level .pb-sl section in a document: every visible control
 * (button, [role=button], [role=tab], summary) must be >= 12 px from every
 * PAINTED text box outside controls. Painted means: has a direct text node,
 * box >= 2 px, and no ancestor with display none / visibility hidden /
 * opacity 0 / clip-to-1px (.visually-hidden). aria-hidden does NOT hide
 * paint: 3D side cards are aria-hidden and still visible, which is the
 * exact case ctl.js skipped (proof keys over side-card text, apex.test).
 *
 * Evaluates to { audit(doc), selfTest() }.
 */
(() => {
  const CTL = 'button, [role="button"], [role="tab"], summary';
  const painted = (el, win) => {
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return null;
    for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
      const s = win.getComputedStyle(e);
      if (s.display === 'none' || s.visibility === 'hidden' || Number(s.opacity) === 0) return null;
      if (e.classList.contains('visually-hidden') || e.hidden) return null;
      if (s.position === 'absolute' && s.clip && s.clip.startsWith('rect(0')) return null;
      if (s.backfaceVisibility === 'hidden' && facesAway(e, win)) return null;
    }
    return r;
  };
  // A 3D ring card turned past 90 degrees shows its back; with
  // backface-visibility hidden it paints nothing, though elementFromPoint
  // still hits it (measured 2026-11-08, 3d.text "Jordan Lee" at 144 deg).
  // The rotation part of the product of every ancestor transform decides
  // it: m33 below 0 means the face points away from the viewer.
  const facesAway = (el, win) => {
    let m = new win.DOMMatrix();
    for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
      const t = win.getComputedStyle(e).transform;
      if (t && t !== 'none') m = new win.DOMMatrix(t).multiply(m);
    }
    return m.m33 < 0;
  };
  const audit = (doc) => {
    const win = doc.defaultView;
    doc.getAnimations().forEach((a) => { try { a.finish(); } catch (e) { /* infinite */ } });
    // elementFromPoint skips pointer-events:none, and 3D side cards often
    // carry it while their words are plainly visible (the apex.test proof
    // bug). Hit-testing must see every painted box, so force it on.
    const pe = doc.createElement('style');
    pe.textContent = '*, *::before, *::after { pointer-events: auto !important; }';
    doc.head.appendChild(pe);
    const out = [];
    const secs = [...doc.querySelectorAll('.vvj-pattern')]
      .filter((s) => !(s.parentElement && s.parentElement.closest('.vvj-pattern')));
    secs.forEach((sec) => {
      // elementFromPoint needs the section on screen.
      sec.scrollIntoView({ block: 'start' });
      const name = (sec.className.match(/vvj-pattern--[a-z0-9-]+/g) || ['?']).join(' ');
      const ctls = [...sec.querySelectorAll(CTL)]
        .filter((c) => !(c.parentElement && c.parentElement.closest(CTL)))
        .map((c) => [c, painted(c, win)]).filter((x) => x[1])
        // A control inside a scrolling strip paints (and takes clicks) only
        // inside the strip (measured 2026-11-09: vvjs ledger dots 44px tall
        // in a 32px strip read as 8px from the counter below it).
        .map(([c, r]) => {
          let l = r.left, t = r.top, rt = r.right, b = r.bottom;
          for (let a = c.parentElement; a && a !== doc.body; a = a.parentElement) {
            const cs = win.getComputedStyle(a);
            if (cs.overflowX !== 'visible' || cs.overflowY !== 'visible') {
              const q = a.getBoundingClientRect();
              l = Math.max(l, q.left); t = Math.max(t, q.top); rt = Math.min(rt, q.right); b = Math.min(b, q.bottom);
            }
          }
          return [c, { left: l, top: t, right: rt, bottom: b }];
        })
        .filter(([, r]) => r.right - r.left > 1 && r.bottom - r.top > 1);
      if (!ctls.length) return;
      // The painted LINES of text (Range rects of each text node), never the
      // element box: a caption box with padding is not text (measured
      // 2026-11-08: progress caption box 129px tall, words only in its top 24px).
      const texts = [];
      [...sec.querySelectorAll('*')]
        .filter((el) => !el.closest(CTL) && painted(el, win))
        .forEach((el) => [...el.childNodes].forEach((n) => {
          if (n.nodeType !== 3 || !n.textContent.trim()) return;
          const range = doc.createRange();
          range.selectNodeContents(n);
          // Cut each line to every clipping ancestor (overflow not visible):
          // a slide hidden by its carousel wrapper paints nothing (measured
          // 2026-11-08: hybrid carousel text 29px below its wrapper's edge).
          const clips = [];
          for (let a = el.parentElement; a && a !== doc.body; a = a.parentElement) {
            const cs = win.getComputedStyle(a);
            if (cs.overflowX !== 'visible' || cs.overflowY !== 'visible') clips.push(a.getBoundingClientRect());
          }
          [...range.getClientRects()].forEach((q) => {
            let l = q.left, t = q.top, r = q.right, b = q.bottom;
            clips.forEach((c) => { l = Math.max(l, c.left); t = Math.max(t, c.top); r = Math.min(r, c.right); b = Math.min(b, c.bottom); });
            if (r - l <= 1 || b - t <= 1) return;
            // Occlusion: a 3D side card behind the front card's solid face
            // paints nothing (measured 2026-11-08, 3d.text "Jordan Lee").
            // The line counts when the text element is on top at any of
            // three points along it.
            const ys = (t + b) / 2;
            const seen = [l + 2, (l + r) / 2, r - 2].some((x) => {
              const hit = doc.elementFromPoint(x, ys);
              return hit && (hit === el || el.contains(hit) || hit.contains(el));
            });
            if (seen) texts.push([el, { left: l, top: t, right: r, bottom: b }]);
          });
        }));
      ctls.forEach(([c, r]) => {
        // An accordion row button owns its own panel; a summary owns its details.
        const own = c.closest('details, .pb-accordion__item, [class*="accordion__item"]');
        const label = (c.getAttribute('aria-label') || c.textContent || c.className).trim().replace(/\s+/g, ' ').slice(0, 22);
        texts.forEach(([t, tr]) => {
          if (own && own.contains(t)) return;
          const gap = Math.max(tr.left - r.right, r.left - tr.right, tr.top - r.bottom, r.top - tr.bottom);
          if (gap < 12) {
            out.push(`${name} | ${label} ${gap < 0 ? 'OVER' : gap + 'px'} "${t.textContent.trim().slice(0, 24)}"`);
          }
        });
      });
    });
    pe.remove();
    return [...new Set(out)];
  };
  const selfTest = () => {
    const host = document.createElement('div');
    // On screen (elementFromPoint needs it), above everything, removed after.
    host.style.cssText = 'position:fixed;left:0;top:0;width:600px;z-index:2147483646;background:#fff';
    host.innerHTML = '<div class="vvj-pattern vvj-pattern--x-a" style="position:relative;height:200px">'
      + '<div aria-hidden="true" style="position:absolute;left:0;top:0;width:200px;pointer-events:none"><p>Side card words</p></div>'
      + '<button style="position:absolute;left:20px;top:0;width:44px;height:44px">Next</button></div>'
      + '<div class="vvj-pattern vvj-pattern--x-c" style="position:relative;height:200px">'
      + '<div style="position:absolute;left:0;top:0;width:200px"><p>Hidden behind</p></div>'
      + '<div style="position:absolute;left:0;top:0;width:300px;height:120px;background:#ccc;z-index:1"></div>'
      + '<button style="position:absolute;left:220px;top:0;width:44px;height:44px;z-index:2">Next</button></div>'
      + '<div class="vvj-pattern vvj-pattern--x-b" style="position:relative;height:200px">'
      + '<div style="position:absolute;left:0;top:0;width:100px"><p>Words</p></div>'
      + '<button style="position:absolute;left:300px;top:150px;width:44px;height:44px">Next</button></div>'
      + '<div class="vvj-pattern vvj-pattern--x-d" style="position:relative;height:200px">'
      + '<div style="position:absolute;left:0;top:0;width:200px;transform:rotateY(150deg);backface-visibility:hidden"><p>Back of ring</p></div>'
      + '<button style="position:absolute;left:20px;top:0;width:44px;height:44px">Next</button></div>';
    document.body.appendChild(host);
    const got = audit(document).filter((f) => f.startsWith('vvj-pattern--x-'));
    host.remove();
    // a: visible side-card words under a key (pointer-events none) -> RED.
    // b: words far from the key -> clean. c: words hidden behind a solid
    // card, key beside them -> clean.
    return {
      pass: got.some((f) => f.startsWith('vvj-pattern--x-a') && f.includes('OVER'))
        && !got.some((f) => f.startsWith('vvj-pattern--x-b') || f.startsWith('vvj-pattern--x-c') || f.startsWith('vvj-pattern--x-d')),
      got,
    };
  };
  return { audit, selfTest };
})()
