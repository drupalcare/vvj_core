/**
 * @file
 * Text-to-edge audit (owner 2026-11-08: "you have a text and background and
 * you are not padded the text so it is touching the edges").
 *
 * For every painted line of text inside a pattern sample (or a top-level
 * .pb-sl section on a real page), find the box whose COLOUR the words sit
 * on: the nearest ancestor with a background colour or image that differs
 * from what is behind it. Its painted area is its border box, grown by a
 * box-shadow spread (the full-bleed grounds), cut by its own clip-path
 * inset, by every clipping ancestor (overflow not visible) and by the
 * viewport. A line closer than MIN_X px to that area's left or right edge,
 * or MIN_Y px to its top or bottom, is a finding.
 *
 * Cannot see: words over an <img> (the picture is a sibling, not an
 * ancestor), rounded corners, colours under 1% alpha difference, text drawn
 * by ::before/::after content.
 *
 * Evaluates to { audit(doc), selfTest() }. audit returns unique strings:
 *   <pattern id> | TOUCH|TIGHT <side> <gap>px "<words>" on <box class>
 */
(() => {
  const MIN_X = 8;
  const MIN_Y = 2;
  const hidden = (el, win) => {
    for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
      const s = win.getComputedStyle(e);
      if (s.display === 'none' || s.visibility === 'hidden' || Number(s.opacity) === 0) return true;
      // A slide waiting behind a closed circle reveal paints nothing.
      if (/^circle\(0(px|%)?\b/.test(s.clipPath)) return true;
      if (e.classList.contains('visually-hidden') || e.hidden) return true;
      if (s.position === 'absolute' && s.clip && s.clip.startsWith('rect(0')) return true;
    }
    return false;
  };
  const solid = (s) => {
    // A gradient drawn as a thin bar (tabs.index's 3px underline) is not a
    // box behind the words (measured 2026-11-08).
    if (s.backgroundImage && s.backgroundImage !== 'none'
      && !(s.backgroundSize.match(/[\d.]+px/g) || []).some((v) => parseFloat(v) <= 6)) return 'image';
    const m = s.backgroundColor.match(/[\d.]+/g);
    if (!m) return null;
    const a = m.length > 3 ? Number(m[3]) : 1;
    return a > 0.01 ? s.backgroundColor : null;
  };
  // The colour behind an element: the first painted ancestor above it.
  const behind = (el, win) => {
    for (let e = el.parentElement; e; e = e.parentElement) {
      const c = solid(win.getComputedStyle(e));
      if (c) return c;
    }
    return 'rgb(255, 255, 255)';
  };
  const px = (v) => parseFloat(v) || 0;
  const paintArea = (el, win, doc) => {
    const s = win.getComputedStyle(el);
    const r = el.getBoundingClientRect();
    let l = r.left, t = r.top, rt = r.right, b = r.bottom;
    // Box-shadow spread (non-inset): 0 0 0 <spread> colour.
    if (s.boxShadow && s.boxShadow !== 'none' && !/inset/.test(s.boxShadow)) {
      const nums = s.boxShadow.replace(/rgba?\([^)]*\)|oklch\([^)]*\)|oklab\([^)]*\)/g, '').match(/-?[\d.]+px/g) || [];
      const spread = nums.length >= 4 ? px(nums[3]) : 0;
      if (spread > 0) { l -= spread; t -= spread; rt += spread; b += spread; }
    }
    // Own clip-path: inset(top right bottom left), CSS shorthand order. The
    // corner radius after `round` is not an inset (measured 2026-11-09:
    // vvjs filmstrip `round 20px` read as a 20px left cut).
    const ci = s.clipPath && s.clipPath.match(/^inset\(([^)]*)\)/);
    if (ci) {
      const v = ci[1].replace(/\s+round\s.*$/, '').split(/\s+/).filter((x) => /px|%|^0$/.test(x)).map(px);
      const [it, ir, ib, il] = [v[0], v[1] ?? v[0], v[2] ?? v[0], v[3] ?? v[1] ?? v[0]];
      l = Math.max(l, r.left + il); t = Math.max(t, r.top + it);
      rt = Math.min(rt, r.right - ir); b = Math.min(b, r.bottom - ib);
    }
    for (let a = el.parentElement; a && a !== doc.documentElement; a = a.parentElement) {
      const cs = win.getComputedStyle(a);
      if (cs.overflowX !== 'visible' || cs.overflowY !== 'visible' || (cs.clipPath && cs.clipPath !== 'none' && !/inset\(0px -/.test(cs.clipPath))) {
        const c = a.getBoundingClientRect();
        if (cs.overflowX !== 'visible') { l = Math.max(l, c.left); rt = Math.min(rt, c.right); }
        if (cs.overflowY !== 'visible') { t = Math.max(t, c.top); b = Math.min(b, c.bottom); }
        // An ancestor's clip-path window paints nothing outside it (measured
        // 2026-11-09: vvjb's centered group clips the next card out of the
        // gutter with an inline inset; its words read as touching).
        const ai = cs.clipPath && cs.clipPath.match(/^inset\(([^)]*)\)/);
        if (ai && !/-/.test(ai[1])) {
          const iv = ai[1].replace(/\s+round\s.*$/, '').split(/\s+/).filter((x) => /px|%|^0$/.test(x)).map(px);
          const [ct, cr, cb, cl] = [iv[0], iv[1] ?? iv[0], iv[2] ?? iv[0], iv[3] ?? iv[1] ?? iv[0]];
          l = Math.max(l, c.left + cl); t = Math.max(t, c.top + ct);
          rt = Math.min(rt, c.right - cr); b = Math.min(b, c.bottom - cb);
        }
      }
    }
    // The visible width without the scrollbar. In RTL the scrollbar sits on
    // the LEFT, so the content area is [innerWidth - clientWidth, innerWidth],
    // not [0, clientWidth] (measured 2026-11-08: 315 false "right 5px" hits
    // at 390 rtl from assuming the LTR side).
    const cw = doc.documentElement.clientWidth;
    const vl = win.getComputedStyle(doc.documentElement).direction === 'rtl' ? win.innerWidth - cw : 0;
    l = Math.max(l, vl); rt = Math.min(rt, vl + cw);
    return { left: l, top: t, right: rt, bottom: b };
  };
  const items = (doc) => {
    const lib = [...doc.querySelectorAll('article.apex-patterns-library-item')];
    if (lib.length) {
      return lib.map((a) => [
        a.querySelector('.apex-patterns-library-item__id code')?.textContent.trim() || '?',
        a.querySelector('.apex-patterns-library-item__sample'),
      ]).filter((x) => x[1]);
    }
    return [...doc.querySelectorAll('.vvj-pattern')]
      .filter((s) => !(s.parentElement && s.parentElement.closest('.vvj-pattern')))
      .map((s) => [(s.className.match(/vvj-pattern--[a-z0-9-]+/g) || ['?']).join(' '), s]);
  };
  const audit = (doc) => {
    const win = doc.defaultView;
    doc.getAnimations().forEach((a) => { try { a.finish(); } catch (e) { /* infinite */ } });
    const out = [];
    items(doc).forEach(([id, root]) => {
      [...root.querySelectorAll('*')].forEach((el) => {
        const own = [...el.childNodes].filter((n) => n.nodeType === 3 && n.textContent.trim());
        if (!own.length || hidden(el, win)) return;
        // The box the words sit on.
        let box = null;
        for (let e = el; e && e !== doc.documentElement; e = e.parentElement) {
          const c = solid(win.getComputedStyle(e));
          if (c && c !== behind(e, win)) { box = e; break; }
        }
        if (!box) return;
        // An inline box broken over lines paints one fragment per line; its
        // union box is not an edge (wrapped <code>, measured 2026-11-08).
        if (win.getComputedStyle(box).display === 'inline') return;
        // A marquee runs its words past the edge on purpose.
        if (box.closest('[class*="marquee"]') || el.closest('[class*="marquee"]')) return;
        // 3D cards: a back face paints nothing; a scaled or turned card shows
        // its padding scaled, so gaps are read in the card's own CSS px.
        let m = new win.DOMMatrix();
        for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
          const t = win.getComputedStyle(e).transform;
          if (t && t !== 'none') m = new win.DOMMatrix(t).multiply(m);
        }
        if (m.m33 < 0) return;
        const br = box.getBoundingClientRect();
        const scale = box.offsetWidth && br.width ? box.offsetWidth / br.width : 1;
        if (!m.isIdentity && (scale > 1.15 || scale < 0.87)) return;
        const area = paintArea(box, win, doc);
        if (area.right - area.left < 4 || area.bottom - area.top < 4) return;
        // Clipping boxes between the words and their colour (a carousel
        // window): a line they cut is scrolled content, not an edge.
        const inner = [];
        for (let a = el; a && a !== box; a = a.parentElement) {
          const cs = win.getComputedStyle(a);
          if (cs.overflowX !== 'visible' || cs.overflowY !== 'visible') inner.push(a.getBoundingClientRect());
        }
        own.forEach((n) => {
          const range = doc.createRange();
          range.selectNodeContents(n);
          [...range.getClientRects()].forEach((q) => {
            if (q.width < 2 || q.height < 2) return;
            if (inner.some((c) => q.left < c.left - 1 || q.right > c.right + 1 || q.top < c.top - 1 || q.bottom > c.bottom + 1)) return;
            // Words cut away by a clipping ancestor (a hidden slide) paint nothing.
            if (q.right <= area.left || q.left >= area.right || q.bottom <= area.top || q.top >= area.bottom) return;
            const gaps = {
              left: q.left - area.left,
              right: area.right - q.right,
              top: q.top - area.top,
              bottom: area.bottom - q.bottom,
            };
            Object.entries(gaps).forEach(([side, g]) => {
              const min = side === 'left' || side === 'right' ? MIN_X : MIN_Y;
              if (g < min) {
                const cls = (box.className && typeof box.className === 'string' ? box.className : box.tagName).trim().split(/\s+/).slice(0, 3).join('.');
                // TOUCH under 4px (the owner's bug); TIGHT 4px up to MIN.
                const tier = g < 4 ? 'TOUCH' : 'TIGHT';
                out.push(`${id} | ${tier} ${side} ${Math.round(g)}px "${n.textContent.trim().slice(0, 24)}" on ${cls}`);
              }
            });
          });
        });
      });
    });
    return [...new Set(out)];
  };
  const selfTest = () => {
    const host = document.createElement('div');
    // No ground of its own: a white host on a tinted page is itself a box
    // (measured 2026-11-09, d11a body rgb(246, 248, 248)).
    host.style.cssText = 'position:fixed;left:100px;top:0;width:600px;z-index:2147483646';
    host.innerHTML = '<article class="apex-patterns-library-item"><p class="apex-patterns-library-item__id"><code>x.touch</code></p>'
      + '<div class="apex-patterns-library-item__sample" style="overflow:clip"><div style="background:#223;color:#fff;box-shadow:0 0 0 2000px #223;clip-path:inset(0 -2000px)"><h2 style="margin:0">Edge words</h2></div></div></article>'
      + '<article class="apex-patterns-library-item"><p class="apex-patterns-library-item__id"><code>x.padded</code></p>'
      + '<div class="apex-patterns-library-item__sample" style="overflow:clip"><div style="background:#223;color:#fff;padding:16px"><h2 style="margin:0">Padded words</h2></div></div></article>'
      + '<article class="apex-patterns-library-item"><p class="apex-patterns-library-item__id"><code>x.round</code></p>'
      + '<div class="apex-patterns-library-item__sample" style="overflow:clip"><div style="background:#223;color:#fff;padding:16px;clip-path:inset(0 round 20px)"><h2 style="margin:0">Rounded words</h2></div></div></article>'
      + '<article class="apex-patterns-library-item"><p class="apex-patterns-library-item__id"><code>x.gutter</code></p>'
      + '<div class="apex-patterns-library-item__sample"><div style="clip-path:inset(0px 450px);overflow:hidden"><div style="background:#223;color:#fff;width:200px;margin-left:520px"><p style="margin:0">Hidden next card</p></div></div></div></article>'
      + '<article class="apex-patterns-library-item"><p class="apex-patterns-library-item__id"><code>x.plain</code></p>'
      + '<div class="apex-patterns-library-item__sample"><div><p style="margin:0">Plain words on the page</p></div></div></article>';
    document.body.appendChild(host);
    const got = audit(document).filter((f) => f.startsWith('x.'));
    host.remove();
    return {
      pass: got.some((f) => f.startsWith('x.touch | TOUCH left'))
        && !got.some((f) => f.startsWith('x.padded') || f.startsWith('x.plain') || f.startsWith('x.round') || f.startsWith('x.gutter')),
      got,
    };
  };
  return { audit, selfTest };
})()
