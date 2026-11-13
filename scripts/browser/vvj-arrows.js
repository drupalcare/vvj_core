/**
 * RTL arrow survey (2026-11-09). For each page in window.__vvjPages, loads
 * /ar/vvj/<page> at 1440 and records, per pattern, the Previous and Next
 * controls: which side each sits on and which way its icon points.
 * Icon direction: the arrow SVG's painted box is compared before and after
 * nothing; instead the computed transform of the svg and its ancestors up to
 * the button is read, and the path's own direction is taken from LTR.
 * Output window.__arr, 'END' last.
 */
(async () => {
  window.__arr = [];
  const flipX = (el, stop, win) => {
    let m = new win.DOMMatrix();
    for (let e = el; e && e !== stop.parentElement; e = e.parentElement) {
      const t = win.getComputedStyle(e).transform;
      if (t && t !== 'none') m = new win.DOMMatrix(t).multiply(m);
    }
    return m.a < 0;
  };
  const read = async (path) => {
    const f = document.createElement('iframe');
    f.style.cssText = 'width:1444px;height:900px;border:0';
    const loaded = new Promise((r) => { f.onload = r; });
    f.src = path;
    document.body.prepend(f);
    await Promise.race([loaded, new Promise((r) => setTimeout(r, 20000))]);
    await new Promise((r) => setTimeout(r, 2500));
    const doc = f.contentDocument;
    const win = f.contentWindow;
    const out = [];
    [...doc.querySelectorAll('.vvj-pattern')].filter((s) => !(s.parentElement && s.parentElement.closest('.vvj-pattern'))).forEach((sec) => {
      const btn = (re) => [...sec.querySelectorAll('button, a, [role=button]')].find((b) => re.test((b.getAttribute('aria-label') || '') + ' ' + b.className));
      const prev = btn(/prev|\u0627\u0644\u0633\u0627\u0628\u0642/i);
      const next = btn(/next|\u0627\u0644\u062a\u0627\u0644\u064a/i);
      if (!prev || !next) return;
      const pr = prev.getBoundingClientRect();
      const nr = next.getBoundingClientRect();
      // The painted arrow: a visible <svg>, else the ::before mask. Mirrored
      // when its `scale` or its transform has a negative x.
      const mir = (b) => {
        const s = b.querySelector('svg');
        if (s && win.getComputedStyle(s).display !== 'none') {
          return flipX(s, b, win) || /^-/.test(win.getComputedStyle(s).scale);
        }
        const bs = win.getComputedStyle(b, '::before');
        return bs.content !== 'none' ? /^-/.test(bs.scale) || new win.DOMMatrix(bs.transform === 'none' ? undefined : bs.transform).a < 0 : null;
      };
      out.push({
        prevLeft: pr.left < nr.left,
        prevFlip: mir(prev),
        nextFlip: mir(next),
        prevText: prev.textContent.trim().slice(0, 3),
        nextText: next.textContent.trim().slice(0, 3),
      });
    });
    f.remove();
    return out;
  };
  for (const p of window.__vvjPages || []) {
    try {
      const rtl = await read('/ar/vvj/' + p);
      rtl.forEach((r) => window.__arr.push(p + ' | prev ' + (r.prevLeft ? 'LEFT' : 'RIGHT')
        + ' flip ' + r.prevFlip + ' ' + r.prevText + ' | next flip ' + r.nextFlip + ' ' + r.nextText));
    }
    catch (e) {
      window.__arr.push('ERR ' + p + ' ' + e.message);
    }
  }
  window.__arr.push('END');
})();
