/**
 * VVJ pattern sweep (2026-11-08). Loads each demo page in an iframe at a
 * width, LTR (/vvj/...) or RTL (/ar/vvj/...), and records:
 * - edge: text closer than 4px to its colored box (edge.js TOUCH),
 * - touch: controls on or within 12px of text (touch.js),
 * - sw: sideways scroll in px,
 * - err: uncaught errors in the frame.
 * Input window.__vvjPages (["vvja/agenda", ...]), __vvjWidths, __vvjDirs.
 * Output window.__vvj (array of strings), 'END' last.
 */
(async () => {
  window.__vvj = [];
  const edgeSrc = await fetch('/sites/d11a/files/edge.js?v=' + Date.now()).then((r) => r.text());
  const touchSrc = await fetch('/sites/d11a/files/touch.js?v=' + Date.now()).then((r) => r.text());
  const pages = window.__vvjPages || [];
  const widths = window.__vvjWidths || [390, 1440];
  const dirs = window.__vvjDirs || ['ltr', 'rtl'];
  for (const p of pages) {
    for (const w of widths) {
      for (const d of dirs) {
        const f = document.createElement('iframe');
        f.style.cssText = 'width:' + (w + 4) + 'px;height:900px;border:0';
        const errs = [];
        const loaded = new Promise((r) => { f.onload = r; });
        f.src = (d === 'rtl' ? '/ar' : '') + '/vvj/' + p + '?sweep=' + w + d;
        document.body.prepend(f);
        try {
          await Promise.race([loaded, new Promise((r) => setTimeout(r, 20000))]);
          const win = f.contentWindow;
          const doc = f.contentDocument;
          // A classic scrollbar steals 15px and, right-to-left, shifts the page, which reads as sideways scroll.
          const noBar = doc.createElement('style');
          noBar.textContent = 'html { scrollbar-width: none; }';
          doc.head.appendChild(noBar);
          win.addEventListener('error', (e) => errs.push(String(e.message).slice(0, 80)));
          doc.querySelectorAll('img[loading=lazy]').forEach((i) => { i.loading = 'eager'; });
          // Narrow region (rule 18, 2026-11-09): hold the pattern to a column
          // of window.__vvjNarrow px on a wide screen, like a sidebar.
          if (window.__vvjNarrow) {
            const st = doc.createElement('style');
            st.textContent = '.vvj-demo-stage { min-width: 0 !important; width: ' + window.__vvjNarrow + 'px !important; max-width: ' + window.__vvjNarrow + 'px !important; margin-inline: auto !important; }';
            doc.head.appendChild(st);
          }
          // Full-width region (rule 18, 2026-11-09): the theme caps content at
          // 903px on a 2560 screen, so a plain run never reaches the ceiling.
          // Open every ancestor of the demo stage.
          if (window.__vvjWide) {
            for (let a = doc.querySelector('.vvj-demo-stage'); a && a !== doc.body; a = a.parentElement) {
              a.style.cssText += ';display:block !important;width:100% !important;max-width:none !important;min-width:0 !important;margin:0 !important;padding:0 !important;';
            }
          }
          await new Promise((r) => setTimeout(r, 3000));
          const edge = win.eval(edgeSrc).audit(doc).filter((s) => /TOUCH/.test(s));
          const touch = win.eval(touchSrc).audit(doc);
          // An image smaller than the clipping frame around it leaves an
          // empty band (vvjl/prints, 2026-11-09: 16:9 photo in a square).
          const holes = [];
          doc.querySelectorAll('.vvj-pattern img').forEach((img) => {
            const r = img.getBoundingClientRect();
            if (r.width < 20 || r.height < 20 || !img.complete) return;
            // A closed face (vvjr/index back boxes) paints nothing.
            for (let e = img; e && e !== doc.body; e = e.parentElement) {
              const cs = win.getComputedStyle(e);
              if (cs.visibility === 'hidden' || Number(cs.opacity) === 0 || e.hidden) return;
            }
            let fe = null;
            let br = img;
            for (let a = img.parentElement; a && !a.classList.contains('vvj-pattern'); br = a, a = a.parentElement) {
              const cs = win.getComputedStyle(a);
              if (cs.overflow !== 'visible' || cs.aspectRatio !== 'auto') { fe = a; break; }
            }
            if (!fe) return;
            // A card: words in flow beside or below the photo fill the rest
            // (vvjp/fieldbook, vvjh/stack). Red-proofed 2026-11-09: mosaic and
            // prints with their fill rule undone give 8 and 12, cards give 0.
            if ([...fe.children].some((c) => c !== br && !/absolute|fixed/.test(win.getComputedStyle(c).position) && c.innerText && c.innerText.trim())) return;
            const fr = fe.getBoundingClientRect();
            if ((r.height < fr.height - 6 || r.width < fr.width - 6) && fr.height > 40) {
              holes.push(Math.round(r.width) + 'x' + Math.round(r.height) + ' in ' + Math.round(fr.width) + 'x' + Math.round(fr.height));
            }
          });
          // Prove the column took effect (2026-11-09: the stage's min-width
          // 100% beat max-width and a whole run measured the wide layout).
          if (window.__vvjNarrow) {
            const pw = doc.querySelector('.vvj-pattern');
            if (pw && pw.getBoundingClientRect().width > window.__vvjNarrow + 1) holes.push('NARROW NOT APPLIED ' + Math.round(pw.getBoundingClientRect().width));
          }
          if (window.__vvjWide) {
            const stage = doc.querySelector('.vvj-demo-stage');
            if (!stage || stage.getBoundingClientRect().width < w - 40) holes.push('WIDE NOT APPLIED ' + (stage ? Math.round(stage.getBoundingClientRect().width) : 'no stage'));
          }
          // Content wider than its pattern box (narrow region overflow).
          const wide = [];
          doc.querySelectorAll('.vvj-pattern').forEach((pt) => {
            if (pt.parentElement && pt.parentElement.closest('.vvj-pattern')) return;
            const over = pt.scrollWidth - pt.clientWidth;
            if (over > 1 && win.getComputedStyle(pt).overflowX === 'visible') wide.push(over);
          });
          if (wide.length) holes.push('pattern wider than its box by ' + wide.join(','));
          // Rule 18 ceiling (2026-11-09): the largest visible text and the
          // widest pattern, so sizes that keep growing with the screen show up.
          let maxFont = 0;
          doc.querySelectorAll('.vvj-pattern *').forEach((n) => {
            if (![...n.childNodes].some((c) => c.nodeType === 3 && c.textContent.trim())) return;
            const r = n.getBoundingClientRect();
            if (r.width < 2 || r.height < 2) return;
            maxFont = Math.max(maxFont, parseFloat(win.getComputedStyle(n).fontSize) || 0);
          });
          const patternBox = doc.querySelector('.vvj-pattern');
          let maxImg = [0, 0];
          doc.querySelectorAll('.vvj-pattern img').forEach((im) => {
            const q = im.getBoundingClientRect();
            if (q.width * q.height > maxImg[0] * maxImg[1]) maxImg = [Math.round(q.width), Math.round(q.height)];
          });
          const ceil = window.__vvjCeil ? ' | img ' + maxImg.join('x') + ' font ' + Math.round(maxFont) + ' box ' + (patternBox ? Math.round(patternBox.getBoundingClientRect().width) + 'x' + Math.round(patternBox.getBoundingClientRect().height) : '-') : '';
          const el = doc.documentElement;
          const sw = el.scrollWidth - el.clientWidth;
          const bad = edge.length || touch.length || sw > 0 || errs.length || holes.length;
          window.__vvj.push((bad ? 'FAIL ' : 'ok ') + p + ' ' + w + ' ' + d + ceil
            + (edge.length ? ' | edge ' + edge.length + ': ' + edge.slice(0, 3).join(' ; ') : '')
            + (touch.length ? ' | touch ' + touch.length + ': ' + touch.slice(0, 3).join(' ; ') : '')
            + (holes.length ? ' | img ' + holes.length + ': ' + holes.slice(0, 2).join(' ; ') : '')
            + (sw > 0 ? ' | sw ' + sw : '')
            + (errs.length ? ' | err ' + errs.join(' ; ') : ''));
        }
        catch (e) {
          window.__vvj.push('ERR ' + p + ' ' + w + ' ' + d + ' ' + e.message);
        }
        f.remove();
      }
    }
  }
  window.__vvj.push('END');
})();
