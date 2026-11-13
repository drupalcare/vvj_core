/**
 * @file
 * Rendered audit of the VVJ Pattern rules a file scan cannot see.
 *
 * R11: no two Patterns share a control style or place, per kind of control.
 * R13: words use a VVJ font, and no button keeps the browser's default ground.
 * R14: words on a solid ground reach 4.5:1 (3:1 at 24px, or 18.66px bold), in
 * the machine's color scheme, or with window.__vvjLight the light tokens.
 *
 * Run in a tab on the demo site. Evaluates to { run(pages, opts), selfTest() }.
 * run() resolves to { rows, findings }; pages are "vvja/agenda" paths. Cannot
 * see: words over a photo, gradient or painted pseudo-element (no solid ground
 * to measure), and text drawn by ::before or ::after.
 */
(() => {
  const CTL = 'button,[role="button"],summary';
  const LIGHT = '.vvj-pattern{--vvj-surface:#fff!important;--vvj-raised:#f2f2f2!important;--vvj-ink:#111!important;--vvj-muted:#555!important;--vvj-line:#d4d4d4!important;color-scheme:light!important}.vvj-demo-stage{background:#fff!important;color:#111!important}';
  // Any CSS color (rgb, color(), oklab ...), read back through a 1px canvas.
  const paint = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
  const rgba = (c) => {
    if (!c || c === 'transparent') return [0, 0, 0, 0];
    paint.clearRect(0, 0, 1, 1);
    paint.fillStyle = '#000';
    paint.fillStyle = c;
    paint.fillRect(0, 0, 1, 1);
    const d = paint.getImageData(0, 0, 1, 1).data;
    return [d[0], d[1], d[2], d[3] / 255];
  };
  const lin = (v) => { const s = v / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; };
  const lum = (c) => 0.2126 * lin(c[0]) + 0.7152 * lin(c[1]) + 0.0722 * lin(c[2]);
  const ratio = (a, b) => (Math.max(lum(a), lum(b)) + 0.05) / (Math.min(lum(a), lum(b)) + 0.05);
  const over = (top, under) => [0, 1, 2].map((i) => top[i] * top[3] + under[i] * (1 - top[3]));
  const shown = (el, win) => {
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return false;
    for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
      const s = win.getComputedStyle(e);
      if (s.display === 'none' || s.visibility === 'hidden' || Number(s.opacity) === 0 || e.classList.contains('visually-hidden')) return false;
    }
    return true;
  };
  // The solid color behind an element, or null when a photo or gradient is.
  const ground = (el, win) => {
    const layers = [];
    for (let e = el; e && e.nodeType === 1; e = e.parentElement) {
      const s = win.getComputedStyle(e);
      if (s.backgroundImage !== 'none') return null;
      // A sliding pill drawn by ::before behind the words (vvjr nocturne, vvjt dock).
      if (['::before', '::after'].some((p) => {
        const q = win.getComputedStyle(e, p);
        const a = rgba(q.backgroundColor);
        return q.content !== 'none' && (q.backgroundImage !== 'none' || (a && a[3] > 0.01));
      })) return null;
      if (e.parentElement && [...e.parentElement.children].some((k) => k !== e && k.matches('img, picture, video, .vvj-media'))) return null;
      if (e.querySelector(':scope > img, :scope > picture, :scope > .vvj-media')) return null;
      // A sliding thumb beside the words (vvjr nocturne's segmented switch).
      const box = e.getBoundingClientRect();
      if (e.parentElement && [...e.parentElement.children].some((k) => {
        if (k === e || win.getComputedStyle(k).position !== 'absolute') return false;
        const a = rgba(win.getComputedStyle(k).backgroundColor);
        const q = k.getBoundingClientRect();
        return a && a[3] > 0.01 && q.left < box.right && q.right > box.left && q.top < box.bottom && q.bottom > box.top;
      })) return null;
      const c = rgba(s.backgroundColor);
      if (c && c[3] > 0.01) {
        layers.push(c);
        if (c[3] >= 0.99) break;
      }
    }
    let base = [255, 255, 255];
    if (layers.length && layers[layers.length - 1][3] < 0.99) return null;
    layers.reverse().forEach((c) => { base = over(c, base); });
    return base;
  };
  const audit = (doc, name, only) => {
    const win = doc.defaultView;
    const found = [];
    const root = only || doc.querySelector('.vvj-pattern');
    if (!root) return { found: [name + ' NO PATTERN'], controls: {} };
    doc.getAnimations().forEach((a) => { try { a.finish(); } catch (e) { /* endless */ } });
    const rs = win.getComputedStyle(root);
    const fonts = ['--vvj-font-ui', '--vvj-font-display', '--vvj-font-mono']
      .map((v) => rs.getPropertyValue(v).trim().replace(/\s+/g, ' ')).filter(Boolean);
    root.querySelectorAll('*').forEach((el) => {
      if (![...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim()) || !shown(el, win)) return;
      const s = win.getComputedStyle(el);
      const fam = s.fontFamily.replace(/\s+/g, ' ');
      const words = el.textContent.trim().slice(0, 20);
      if (fonts.length && !fonts.some((f) => fam === f)) found.push(`R13 ${name} font "${fam.slice(0, 30)}" on "${words}"`);
      const bg = ground(el, win);
      const ink = rgba(s.color);
      if (bg && ink && !el.closest('[aria-disabled="true"], :disabled')) {
        const size = parseFloat(s.fontSize);
        const need = size >= 24 || (size >= 18.66 && Number(s.fontWeight) >= 700) ? 3 : 4.5;
        const r = ratio(over(ink, bg), bg);
        if (r < need - 0.01) found.push(`R14 ${name} ${r.toFixed(2)}:1 need ${need} on "${words}"`);
      }
    });
    const controls = {};
    const box = root.getBoundingClientRect();
    root.querySelectorAll(CTL).forEach((c) => {
      if (!shown(c, win) || c.getBoundingClientRect().width < 8) return;
      const s = win.getComputedStyle(c);
      if (/^(ButtonFace|rgb\(239, 239, 239\)|rgb\(107, 107, 107\))$/.test(s.backgroundColor) && s.borderTopStyle === 'outset') {
        found.push(`R13 ${name} button keeps the browser ground: ${(c.getAttribute('aria-label') || c.className).slice(0, 24)}`);
      }
      const lab = (c.getAttribute('aria-label') || c.className || '').toLowerCase();
      // A tile opener labelled "Expand slide 1" is not a navigation dot.
      const kind = /expand/.test(lab) ? '' : /prev/.test(lab) ? 'prev' : /next/.test(lab) ? 'next' : /play|pause/.test(lab) ? 'play' : /dot|slide \d|go to/.test(lab) ? 'dot' : '';
      if (!kind || controls[kind]) return;
      const r = c.getBoundingClientRect();
      controls[kind] = {
        style: [Math.round(r.width), Math.round(r.height), s.borderRadius, s.backgroundColor, s.borderTopWidth, s.borderTopColor].join('/'),
        place: Math.min(4, Math.max(0, Math.floor(((r.left + r.width / 2 - box.left) / box.width) * 5))) + ',' + Math.min(4, Math.max(0, Math.floor(((r.top + r.height / 2 - box.top) / box.height) * 5))),
      };
    });
    return { found, controls };
  };
  // R11 (owner, 2026-11-09): no two Patterns share a control style, or the
  // same overall layout of their controls.
  const sameControls = (rows) => {
    const out = [];
    const report = (label, groups) => Object.entries(groups).forEach(([sig, pages]) => {
      if (pages.length > 1) out.push(`R11 ${label} ${sig.slice(0, 50)}: ${pages.join(' ')}`);
    });
    const KINDS = ['prev', 'next', 'play', 'dot'];
    KINDS.forEach((kind) => {
      const groups = {};
      rows.forEach((r) => {
        const c = r.controls[kind];
        if (c) (groups[c.style] = groups[c.style] || []).push(r.page);
      });
      report(`style ${kind}`, groups);
    });
    const layouts = {};
    rows.filter((r) => Object.keys(r.controls).length).forEach((r) => {
      const sig = KINDS.map((k) => `${k}:${r.controls[k] ? r.controls[k].place : '-'}`).join(' ');
      (layouts[sig] = layouts[sig] || []).push(r.page);
    });
    report('layout', layouts);
    return out;
  };
  const run = async (pages, opts = {}) => {
    const rows = [];
    const width = opts.width || 2560;
    for (const page of pages) {
      const f = document.createElement('iframe');
      f.style.cssText = `width:${width + 4}px;height:${opts.height || 900}px;border:0`;
      const loaded = new Promise((r) => { f.onload = r; });
      f.src = `/vvj/${page}?audit=1`;
      document.body.prepend(f);
      await Promise.race([loaded, new Promise((r) => { setTimeout(r, 20000); })]);
      const doc = f.contentDocument;
      for (let a = doc.querySelector('.vvj-demo-stage'); a && a !== doc.body; a = a.parentElement) {
        a.style.cssText += ';display:block !important;width:100% !important;max-width:none !important;min-width:0 !important;margin:0 !important;padding:0 !important;';
      }
      if (window.__vvjLight) {
        const st = doc.createElement('style');
        st.textContent = LIGHT;
        doc.head.appendChild(st);
      }
      await new Promise((r) => { setTimeout(r, opts.settle || 2200); });
      rows.push({ page, ...audit(doc, page) });
      f.remove();
    }
    return { rows, findings: [...new Set(rows.flatMap((r) => r.found))].concat(sameControls(rows)) };
  };
  const selfTest = () => {
    const host = document.createElement('div');
    host.style.cssText = 'position:fixed;left:0;top:0;width:500px;z-index:2147483646';
    host.innerHTML = '<div class="vvj-pattern" style="background:#fff;font-family:var(--vvj-font-ui)">'
      + '<p style="color:#ccc;background:#fff;font-family:var(--vvj-font-ui)">Pale words</p>'
      + '<p style="color:#111;background:#fff;font-family:var(--vvj-font-ui)">Dark words</p>'
      + '<p style="color:#111;background:#fff;font-family:Courier">Wrong font</p></div>';
    document.body.appendChild(host);
    const r = audit(host.ownerDocument, 'x', host.firstChild);
    const onlyX = r.found.filter((s) => /Pale|Dark|Wrong/.test(s));
    host.remove();
    const lab = rgba('oklab(0.95 0 0)');
    const shared = sameControls([
      { page: 'a', controls: { prev: { style: 's1', place: '0,0' } } },
      { page: 'b', controls: { prev: { style: 's1', place: '1,0' } } },
      { page: 'c', controls: { prev: { style: 's2', place: '2,0' } } },
      { page: 'd', controls: { prev: { style: 's3', place: '3,0' } } },
      { page: 'e', controls: { prev: { style: 's4', place: '3,0' } } },
    ]);
    return {
      pass: lab[0] > 200 && onlyX.some((s) => s.startsWith('R14') && /Pale/.test(s))
        && onlyX.some((s) => s.startsWith('R13') && /Wrong/.test(s))
        && !onlyX.some((s) => /Dark/.test(s))
        && shared.length === 2 && /style prev/.test(shared[0]) && /layout/.test(shared[1]),
      onlyX,
      shared,
    };
  };
  return { run, selfTest };
})()
