#!/usr/bin/env python3
"""VVJ motion check: hover rules that move their own box, and JS that leaks listeners.

Static only: reads CSS and JS under each VVJ repo given (tests/, node_modules/, vendor/ and *.min.* skipped).
The rules it enforces are written out, with the why and an example, in docs/JS-RULES.md (one source of truth:
this script cites the rule numbers, it does not restate them).

FAIL (exit 1):
  (a) CSS rule 1. A selector part whose SUBJECT compound (the last one) carries :hover and whose declarations set
      transform, translate, scale, rotate, margin*, top, right, bottom, left, inset*, width or height. At the edge of
      the box the element leaves the cursor, hover drops, it moves back, hover starts again: the shake.
      Safe and listed, not failed: the motion is on a pseudo-element (::before/::after), on a descendant of the
      hovered element (`.item:hover .inner`), or it is a growing zoom (only scale() >= 1 in the transform, or the
      `scale` property >= 1): a box scaled up about a point inside itself still covers the cursor, so it cannot shake.
      Also safe: a rest pin (the same rule also lists the selector without :hover, so hover = rest), a reset
      (transform/translate/scale/rotate: none), and a hit-area guard (the same file has `<selector>::after` or
      `::before` with a negative inset, which keeps the moved box under the cursor; the fallback when the
      hovered box has no stable wrapper).
      A selector part whose subject has only :focus / :focus-visible / :focus-within is WARN, not FAIL: focus does not
      follow the cursor, so it cannot shake (it still jumps the box; rule 1 in JS-RULES.md).
  (b) JS rule 6. A file that adds a document/window listener (addEventListener or jQuery .on) without a matching
      removeEventListener / .off for the same event name, or calls setInterval without clearInterval, or creates a
      ResizeObserver / IntersectionObserver / MutationObserver without disconnect(); or does any of those and has no
      `detach` in a Drupal behavior.
  (e) Rule 11. A mode button (MODE_BUTTONS: vvjr-toggle, vvjf-turn) styled on :hover or :active without its
      mode class (--hover / --click), or a JS selector string naming the bare class in a file that adds click
      listeners: such a rule or handler reaches both modes.
  (c) JS rule 7. A file that defines a Drupal behavior (Drupal.behaviors.X) and never calls once().
WARN (exit 0 unless --strict):
  (d) JS rule 3. Inside one loop body (for / while / forEach / map), a layout read (offsetWidth/Height/Top/Left,
      clientWidth/Height, scrollWidth/Height, getBoundingClientRect, getComputedStyle) followed by a style write.

Heuristics (needs checking on a page): CSS nesting is not parsed (VVJ CSS has none); media queries are flattened;
the JS checks are per file, by name, not by data flow, so a remove in the wrong function still passes (b).

Usage: python3 -I check-vvj-motion.py [--strict] [--list] [PATH ...]    (default PATH: the eleven VVJ repos next to
this one)       python3 -I check-vvj-motion.py --self-test
Exit 0 pass, 1 fail, 2 usage or input error. The last line names the repos read: "motion: N repos".
"""
import argparse, os, re, shutil, sys, tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPOS = ['vvj_core', 'vvja', 'vvjb', 'vvjc', 'vvjf', 'vvjh', 'vvjl', 'vvjp', 'vvjr', 'vvjs', 'vvjt']
WALK_SKIP = {'tests', 'node_modules', '.git', 'vendor', 'web'}
MOVE_PROPS = re.compile(r'^(transform|translate|scale|rotate|margin(-[a-z-]+)?|top|right|bottom|left|inset(-[a-z-]+)?'
                        r'|width|height|min-width|min-height|max-width|max-height|inline-size|block-size|padding(-[a-z-]+)?)$')
LAYOUT_READ = re.compile(r'\b(offset(Width|Height|Top|Left)|client(Width|Height)|scroll(Width|Height)'
                         r'|getBoundingClientRect|getComputedStyle)\b')
STYLE_WRITE = re.compile(r'\.style\.[A-Za-z]+\s*=(?!=)|\.style\.setProperty\(|\.style\.cssText\s*=(?!=)')
MODE_BUTTONS = ('vvjr-toggle', 'vvjf-turn')
LOOP_HEAD = re.compile(r'\b(for|while)\s*\(|\.(forEach|map)\s*\(')


def walk(root, exts):
    for d, dirs, files in os.walk(root, followlinks=True):
        dirs[:] = sorted(x for x in dirs if x not in WALK_SKIP)
        for f in sorted(files):
            if f.endswith(exts) and '.min.' not in f:
                yield os.path.join(d, f)


# ---------------------------------------------------------------- CSS

def css_rules(text):
    """Yield (selector, body, line) for every style rule; at-rule blocks are entered, not reported."""
    text = re.sub(r'/\*.*?\*/', lambda m: re.sub(r'[^\n]', ' ', m.group(0)), text, flags=re.S)
    i, n, start = 0, len(text), 0
    while i < n:
        c = text[i]
        if c == '{':
            head = text[start:i].strip()
            if head.startswith('@'):
                start = i + 1
            else:
                j, depth = i + 1, 1
                while j < n and depth:
                    depth += {'{': 1, '}': -1}.get(text[j], 0)
                    j += 1
                yield head, text[i + 1:j - 1], text.count('\n', 0, i - len(text[start:i].lstrip())) + 1
                start = j
                i = j
                continue
        elif c in '};':
            start = i + 1
        i += 1


def split_top(s, sep):
    out, depth, cur = [], 0, ''
    for ch in s:
        depth += (ch in '([') - (ch in ')]')
        if ch == sep and depth == 0:
            out.append(cur)
            cur = ''
        else:
            cur += ch
    out.append(cur)
    return [x.strip() for x in out if x.strip()]


def subject(part):
    """Last compound of a selector, with :not()/:has()/:is()/:where() arguments blanked."""
    flat = re.sub(r'\s*([>+~])\s*', ' ', part.strip())
    comps = [c for c in split_top(flat, ' ')]
    last = comps[-1] if comps else ''
    blank = ''
    depth = 0
    for ch in last:
        if ch == '(':
            depth += 1
        elif ch == ')':
            depth -= 1
        elif depth == 0:
            blank += ch
    return blank


def growing_zoom(prop, value):
    v = value.replace('!important', '').strip()
    if prop == 'scale':
        nums = re.findall(r'-?[\d.]+', v)
        return bool(nums) and all(float(x) >= 1 for x in nums)
    if prop == 'transform':
        fns = re.findall(r'([a-zA-Z0-9]+)\(([^)]*)\)', v)
        if not fns or re.sub(r'[a-zA-Z0-9]+\([^)]*\)', '', v).strip():
            return False
        return all(f in ('scale', 'scale3d') and all(float(x) >= 1 for x in re.findall(r'-?[\d.]+', a))
                   for f, a in fns)
    return False


def norm(sel):
    return re.sub(r'\s+', ' ', sel).strip()


def unwhere(sel):
    """`:where(X)Y` with one balanced :where() around the whole selector head -> `XY`; else sel unchanged."""
    if not sel.startswith(':where('):
        return sel
    depth = 0
    for i in range(6, len(sel)):
        depth += (sel[i] == '(') - (sel[i] == ')')
        if depth == 0:
            # Only a head wrapped whole: what follows the ')' is at most a pseudo-element.
            return sel[7:i] + sel[i + 1:] if re.fullmatch(r'(::?[\w-]+)?', sel[i + 1:]) else sel
    return sel


def unwhere_head(sel):
    """`:where(X) Y` (a :where() around the selector head only) -> `X Y`; whole wraps go through unwhere()."""
    sel = unwhere(sel)
    if not sel.startswith(':where('):
        return sel
    depth = 0
    for i in range(6, len(sel)):
        depth += (sel[i] == '(') - (sel[i] == ')')
        if depth == 0:
            return norm(sel[7:i] + sel[i + 1:])
    return sel


def ancestors(part):
    """Selector text before the subject compound."""
    flat = re.sub(r'\s*([>+~])\s*', r' \1 ', norm(part))
    comps = split_top(flat, ' ')
    return ' '.join(comps[:-1]), (comps[-1] if comps else '')


def check_css(path, text, rel):
    fails, warns, safe = [], [], []
    rules = list(css_rules(text))
    guards = set()
    for sel, body, line in rules:
        if re.search(r'inset\s*:\s*-', body):
            for part in split_top(sel, ','):
                m = re.match(r'(.*:hover)::?(after|before)$', norm(unwhere_head(norm(part))))
                if m:
                    guards.add(m.group(1))
    for sel, body, line in rules:
        for part in split_top(sel, ','):
            subj = subject(part)
            for b in MODE_BUTTONS:
                if re.search(r'\.%s(?![-\w])' % b, subj) and re.search(r':(hover|active)', subj):
                    fails.append('%s:%d  (e) %s  mode button state rule reaches both modes' % (rel, line, norm(part)))
        decls = []
        for d in body.split(';'):
            if ':' in d:
                p, v = d.split(':', 1)
                p = p.strip().lower()
                if MOVE_PROPS.match(p):
                    decls.append((p, v.strip()))
        if not decls:
            continue
        parts = split_top(sel, ',')
        rest = {norm(x) for x in parts}
        tag_rest = {ancestors(x)[0] for x in parts if ':' not in x and re.fullmatch(r'[a-z][\w-]*', ancestors(x)[1])}
        for part in parts:
            if ':hover' not in part and ':focus' not in part:
                continue
            subj = subject(part)
            if norm(part.replace(':hover', '')) in rest:
                safe.append('%s:%d  %s   [rest pin: hover equals rest]' % (rel, line, norm(part)))
                continue
            anc, last = ancestors(part)
            if anc in tag_rest and not re.match(r'[a-z]', last):
                # Same rule pins the rest state of every <tag> in this context; the hovered element is assumed to be
                # that tag (needs checking against the template when the rule changes).
                safe.append('%s:%d  %s   [rest pin: same values as the bare-tag rest part]' % (rel, line, norm(part)))
                continue
            where = '%s:%d  %s  { %s }' % (rel, line, part, '; '.join('%s: %s' % d for d in decls))
            if '::' in subj or re.search(r'(?<!:):(before|after)\b', subj):
                safe.append(where + '   [pseudo-element moves]')
                continue
            if ':hover' in subj:
                moving = [d for d in decls if not growing_zoom(*d)
                          and not (d[0] in ('transform', 'translate', 'scale', 'rotate') and d[1].split('!')[0].strip() == 'none')]
                if moving and norm(unwhere_head(norm(part))) in guards:
                    safe.append(where + '   [hit-area guard]')
                elif moving:
                    fails.append(where)
                else:
                    safe.append(where + '   [growing zoom]')
            elif ':focus' in subj:
                warns.append(where + '   [focus only: cannot shake]')
            elif ':hover' in part:
                safe.append(where + '   [hover on an ancestor; the hovered box does not move]')
    return fails, warns, safe


# ---------------------------------------------------------------- JS

def block_after(text, idx):
    """Text of the {...} block whose '{' is the first one at or after idx."""
    i = text.find('{', idx)
    if i < 0:
        return ''
    depth, j = 0, i
    while j < len(text):
        depth += {'{': 1, '}': -1}.get(text[j], 0)
        j += 1
        if depth == 0:
            break
    return text[i:j]


def strip_js(text):
    code = re.sub(r'/\*.*?\*/', '', text, flags=re.S)
    # Keep `// vvj-motion: allow ...` markers: the (d) rule reads them.
    return re.sub(r'(?m)^\s*//(?!\s*vvj-motion:).*$', '', code)


def call_args(code, open_paren):
    """Text between the '(' at open_paren and its matching ')'."""
    depth = 0
    for j in range(open_paren, len(code)):
        depth += (code[j] == '(') - (code[j] == ')')
        if depth == 0:
            return code[open_paren + 1:j]
    return code[open_paren + 1:]


ALLOW_D = re.compile(r'//[ \t]*vvj-motion:[ \t]*allow[ \t]*\(d\)[ \t]+\S')
FN_HEAD = re.compile(r'=>\s*\{|\bfunction\b[^{(]*\([^)]*\)\s*\{|^\s*(?!if\b|for\b|while\b|switch\b|catch\b)\w+\s*\([^)]*\)\s*\{', re.M)


def without_nested_functions(body):
    """The block with the bodies of functions declared inside it blanked (their own code runs later, not here)."""
    inner = body[1:]
    out, i = [], 0
    for m in FN_HEAD.finditer(inner):
        if m.start() < i:
            continue
        blk = block_after(inner, m.end() - 1)
        out.append(inner[i:m.end() - 1])
        i = m.end() - 1 + len(blk)
    out.append(inner[i:])
    return '{' + ''.join(out)


def cleanup_proofs(texts):
    """Pass 1 over every JS file read: which classes abort a signal on disconnect, which helpers abort on detach.

    A base proves cleanup when the class defines `get signal()` and its disconnectedCallback calls .abort(); both
    its class name and the property it is assigned to (`Drupal.Vvj.ElementBase = class VvjElementBase`) count.
    A helper proves it when a method body calls once() and .abort() (the shared P.behavior(key, selector, setup)).
    """
    bases, helpers = set(), set()
    for text in texts:
        code = strip_js(text)
        for m in re.finditer(r'(?:([\w$]+)\s*=\s*)?class\s+([\w$]+)[^{]*\{', code):
            body = block_after(code, m.end() - 1)
            dc = re.search(r'\bdisconnectedCallback\s*\(\s*\)\s*\{', body)
            if re.search(r'\bget\s+signal\s*\(', body) and dc and '.abort()' in block_after(body, dc.end() - 1):
                bases.update(x for x in m.groups() if x)
        for m in re.finditer(r'^\s*([\w$]+)\s*\(\s*key\s*,[^)]*\)\s*\{', code, re.M):
            body = block_after(code, m.end() - 1)
            if re.search(r'\bonce\(', body) and '.abort()' in body:
                helpers.add(m.group(1))
    return bases, helpers


def signal_proven(code, args, proofs):
    """True when a listener's options carry a signal whose abort the code proves (rule 6, JS-RULES.md)."""
    m = re.search(r'\bsignal\s*:\s*([\w$.]+)|[{,]\s*signal\s*[,}]', args)
    if not m:
        return False
    expr = m.group(1) or 'signal'
    bases, helpers = proofs
    if expr == 'this.signal':
        ext = re.search(r'\bextends\s+([\w$.]+)', code)
        if not ext or ext.group(1).split('.')[-1] not in bases:
            return False
        own = re.search(r'\bdisconnectedCallback\s*\(\s*\)\s*\{', code)
        return not own or 'super.disconnectedCallback' in block_after(code, own.end() - 1)
    name = expr.split('.')[0]
    for h in helpers:
        for c in re.finditer(r'\.%s\(\s*[\'"][^\'"]*[\'"]\s*,[^,]*,\s*(?:function\s*[\w$]*\s*)?\(([^)]*)\)' % re.escape(h), code):
            if name in [x.strip() for x in c.group(1).split(',')]:
                return True
    # Delegated: the signal is a required parameter of the function that adds the listener (a shared helper such
    # as Drupal.Vvj.onState(root, cb, signal) or `constructor(root, { signal })`). Aborting it is the caller's job,
    # and the caller is checked in its own file. A defaulted parameter (`signal = x`) does not count.
    if name == 'signal' and expr == 'signal':
        for fm in re.finditer(r'(?:\bconstructor|=>|\bfunction\b[^(]*|[\w$]+)\s*\(([^()]*)\)\s*(?:=>)?\s*\{', code):
            params = re.sub(r'[{}\s]', '', fm.group(1)).split(',')
            if 'signal' in params:
                return True
        for fm in re.finditer(r'=\s*\(([^()]*)\)\s*=>', code):
            params = re.sub(r'[{}\s]', '', fm.group(1)).split(',')
            if 'signal' in params:
                return True
    # A local AbortController aborted in a detach or a disconnectedCallback of the same file.
    if re.search(r'new\s+AbortController\b', code):
        for d in re.finditer(r'\b(detach|disconnectedCallback)\s*\([^)]*\)\s*\{', code):
            if '.abort()' in block_after(code, d.end() - 1):
                return True
    return False


def check_js(path, text, rel, proofs=(frozenset(), frozenset())):
    fails, warns = [], []
    code = strip_js(text)
    leaks, unproven_global = [], False
    for m in re.finditer(r'\b(document|window)\s*\.\s*addEventListener\(\s*[\'"]([\w:.-]+)[\'"]', code):
        if signal_proven(code, call_args(code, m.end() - len(m.group(0)) + m.group(0).index('(')), proofs):
            continue
        unproven_global = True
        if not re.search(r'removeEventListener\(\s*[\'"]%s[\'"]' % re.escape(m.group(2)), code):
            leaks.append('%s listener "%s" never removed' % (m.group(1), m.group(2)))
    for m in re.finditer(r'\$\(\s*(document|window)\s*\)\s*\.on\(\s*[\'"]([\w:. -]+)[\'"]', code):
        unproven_global = True
        if not re.search(r'\.off\(\s*[\'"]%s' % re.escape(m.group(2).split()[0]), code):
            leaks.append('jQuery %s .on("%s") never .off()' % (m.group(1), m.group(2)))
    if re.search(r'\bsetInterval\(', code):
        unproven_global = True
        if not re.search(r'\bclearInterval\(', code):
            leaks.append('setInterval without clearInterval')
    if re.search(r'new\s+(Resize|Intersection|Mutation)Observer\b', code) and not re.search(r'\.disconnect\(', code):
        leaks.append('observer without disconnect()')
    if re.search(r'\.behaviors\.', code) and unproven_global and not re.search(r'\bdetach\s*[:(]', code):
        leaks.append('global listener or timer, and no detach in the behavior')
    for l in leaks:
        fails.append('%s  (b) %s' % (rel, l))
    via_helper = any(re.search(r'\.behaviors\.\w+\s*=\s*[\w$.]+\.%s\(' % re.escape(h), code) for h in proofs[1])
    if re.search(r'\.behaviors\.\w+\s*=', code) and not re.search(r'\bonce\(', code) and not via_helper:
        fails.append('%s  (c) Drupal behavior without once()' % rel)
    if re.search(r'addEventListener\(\s*[\'"]click', code):
        for m in re.finditer(r'[\'"`]([^\'"`\n]*)[\'"`]', code):
            for b in MODE_BUTTONS:
                if re.search(r'\.%s(?![-\w])' % b, m.group(1)):
                    fails.append('%s  (e) selector "%s" reaches both modes of %s' % (rel, m.group(1), b))
    # (d) A loop body (nested function bodies left out: they run later, once per event) that reads layout and then
    # writes style thrashes from the second iteration on. A function body that writes style and then reads layout
    # forces a synchronous layout on every call.
    for m in LOOP_HEAD.finditer(code):
        body = without_nested_functions(block_after(code, m.end()))
        r = LAYOUT_READ.search(body)
        if r and STYLE_WRITE.search(body[r.end():]):
            warns.append('%s:%d  (d) layout read then style write in one loop' % (rel, code.count('\n', 0, m.start()) + 1))
    for m in FN_HEAD.finditer(code):
        # An intentional write-then-read is allowed by a comment with a reason, on the function's head line or as the
        # first line of its body (where Prettier puts it): `// vvj-motion: allow (d) <reason>`. No reason, no allow.
        # The head line is the line holding the opening brace (the match can start on an earlier blank line).
        brace = m.end() - 1
        end = code.find('\n', brace)
        end2 = code.find('\n', end + 1) if end != -1 else -1
        head_line = code[code.rfind('\n', 0, brace) + 1:end2 if end2 != -1 else len(code)]
        if ALLOW_D.search(head_line):
            continue
        body = without_nested_functions(block_after(code, m.end() - 1))
        w = STYLE_WRITE.search(body)
        if w and LAYOUT_READ.search(body[w.end():]):
            warns.append('%s:%d  (d) style write then layout read (forced layout)' % (rel, code.count('\n', 0, m.start()) + 1))
    return fails, warns


# ---------------------------------------------------------------- run

def run(paths, strict=False, listing=False, out=sys.stdout):
    fails, warns, safe, read = [], [], [], 0
    texts = []
    for root in paths:
        if os.path.isdir(root):
            for p in walk(root, ('.js',)):
                with open(p, encoding='utf-8', errors='replace') as fh:
                    texts.append(fh.read())
    proofs = cleanup_proofs(texts)
    for root in paths:
        if not os.path.isdir(root):
            print('motion: not a directory: %s' % root, file=sys.stderr)
            return 2
        read += 1
        base = os.path.dirname(os.path.abspath(root))
        for p in walk(root, ('.css',)):
            if p.endswith('-admin.css'):
                continue
            with open(p, encoding='utf-8', errors='replace') as fh:
                f, w, s = check_css(p, fh.read(), os.path.relpath(p, base))
            fails += f
            warns += w
            safe += s
        for p in walk(root, ('.js',)):
            with open(p, encoding='utf-8', errors='replace') as fh:
                f, w = check_js(p, fh.read(), os.path.relpath(p, base), proofs)
            fails += f
            warns += w
    for x in fails:
        print('FAIL  ' + x, file=out)
    for x in warns:
        print('WARN  ' + x, file=out)
    if listing:
        for x in safe:
            print('SAFE  ' + x, file=out)
    print('motion: %d fail, %d warn, %d safe-listed; %d repos' % (len(fails), len(warns), len(safe), read), file=out)
    if fails or (strict and warns):
        return 1
    return 0


SELF = {
    # name: (file, content, expected substring in output or None for green)
    'red-a-self-translate': ('x/css/a.css', '.b:hover { transform: translateY(-2px); }', 'FAIL  x/css/a.css:1'),
    'red-a-self-margin': ('x/css/m.css', '@media (min-width: 1px) {\n.b:hover,\n.b:focus-visible { margin-top: 1px; }\n}',
                          'FAIL  x/css/m.css:2  .b:hover'),
    'red-a-flip-self': ('x/css/f.css', '.card-inner:hover { transform: rotateY(180deg); }', 'FAIL  x/css/f.css:1'),
    'red-b-listener': ('x/js/b.js', "(function(D){D.behaviors.x={attach(c){once('x','.x',c);"
                       "document.addEventListener('keydown',f);}};})(Drupal);", '(b) document listener "keydown"'),
    'red-b-interval': ('x/js/i.js', "(function(D){D.behaviors.x={attach(c){once('x','.x',c);setInterval(f,9);},"
                       "detach(){}};})(Drupal);", '(b) setInterval without clearInterval'),
    'red-c-no-once': ('x/js/c.js', "(function(D){D.behaviors.x={attach(c){c.querySelectorAll('.x');}};})(Drupal);",
                      '(c) Drupal behavior without once()'),
    'red-d-thrash': ('x/js/d.js', "(function(D){D.behaviors.x={attach(c){once('x','.x',c).forEach(function(e){"
                     "var w=e.offsetWidth;e.style.height=w+'px';});}};})(Drupal);", 'WARN  x/js/d.js:1  (d)'),
    'red-e-mode-css': ('x/css/e.css', '.v .vvjr-toggle:hover { color: red; }', '(e) .v .vvjr-toggle:hover'),
    'red-e-mode-js': ('x/js/e.js', "(function(D){D.behaviors.x={attach(c){once('x','.x',c).forEach(function(e){"
                      "e.querySelector('.vvjr-toggle').addEventListener('click',f);});}};})(Drupal);",
                      '(e) selector ".vvjr-toggle"'),
    'ok-mode-split': ('y/css/e.css', '.v .vvjr-toggle--click:hover { color: red; }\n.v .vvjr-toggle { color: blue; }', None),
    'ok-guard': ('y/css/g.css', '.b:hover { transform: translateY(-2px); }\n.b:hover::after { content: ""; position: absolute; inset: -4px; }', None),
    'ok-pin-reset': ('y/css/r.css', '.n button,\n.n button:hover { width: 44px; }\n.d:hover { transform: none; }', None),
    'ok-parent-hover': ('y/css/p.css', '.item:hover .inner { transform: translateY(-2px); }', None),
    'ok-clipped-zoom': ('y/css/z.css', '.frame img:hover { transform: scale(1.05); }', None),
    'ok-pseudo': ('y/css/s.css', '.b:hover::after { transform: translateY(-2px); }', None),
    'ok-no-move': ('y/css/c.css', '.b:hover { color: red; box-shadow: 0 2px 4px #000; }', None),
    'ok-js': ('y/js/ok.js', "(function(D){function k(){}D.behaviors.x={attach(c){once('x','.x',c);"
              "document.addEventListener('keydown',k);var t=setInterval(k,9);},detach(){"
              "document.removeEventListener('keydown',k);clearInterval(t);}};})(Drupal);", None),
    'red-a-padding': ('x/css/pd.css', '.b:hover { padding: 0 24px; }', 'FAIL  x/css/pd.css:1'),
    'ok-where-guard': ('y/css/wg.css', '.v .b:hover { transform: translateY(-2px); }\n:where(.v .b:hover)::after { content: ""; position: absolute; inset: -4px; }', None),
    'ok-where-head-guard': ('y/css/wh.css', ':where(.v .h) .b:hover { transform: translateY(-2px); }\n:where(.v .h .b:hover)::after { content: ""; position: absolute; inset: -4px; }', None),
    'red-where-head-no-guard': ('x/css/wn.css', ':where(.v .h) .b:hover { transform: translateY(-2px); }', 'FAIL  x/css/wn.css:1'),
    'ok-pin-tag': ('y/css/pt.css', '.n button,\n.n button:hover,\n.n .button.off:hover { width: 44px; margin: 0; transform: none; }', None),
    'red-pin-not-tag': ('x/css/pn.css', '.n .btn,\n.n .button.off:hover { width: 44px; }', 'FAIL  x/css/pn.css:1  .n .button.off:hover'),
    'ok-b-element-base': ([('y/js/base.js', "(function(D){D.Vvj.ElementBase=class VvjElementBase extends HTMLElement{get signal(){if(!this._c){this._c=new AbortController();}return this._c.signal;}\n  disconnectedCallback() {\n this._c?.abort();\n }\n};})(Drupal);"), ('y/js/el.js', "(function(D){const {ElementBase}=D.Vvj;class E extends ElementBase{onHydrate(){"
                          "window.addEventListener('resize',f,{ signal: this.signal });}}})(Drupal);")], None),
    'red-b-base-override': ([('x/js/base.js', "(function(D){D.Vvj.ElementBase=class VvjElementBase extends HTMLElement{get signal(){if(!this._c){this._c=new AbortController();}return this._c.signal;}\n  disconnectedCallback() {\n this._c?.abort();\n }\n};})(Drupal);"), ('x/js/el.js', "(function(D){const {ElementBase}=D.Vvj;class E extends ElementBase{onHydrate(){"
                            "window.addEventListener('resize',f,{ signal: this.signal });}\n  disconnectedCallback() {\n this.x=0;\n }\n}})(Drupal);")],
                            '(b) window listener "resize" never removed'),
    'red-b-no-base': ('x/js/nb.js', "(function(D){class E extends HTMLElement{connectedCallback(){"
                      "window.addEventListener('resize',f,{ signal: this.signal });}}})(Drupal);", '(b) window listener "resize" never removed'),
    'ok-b-c-helper': ([('y/js/helper.js', "(function(D,once){D.P={\n  behavior(key, selector, setup) {\n var c=new WeakMap();return{attach(x){once(key,selector,x).forEach(function(r){var a=new AbortController();c.set(r,a);setup(r,a.signal);});},detach(x){c.get(x)?.abort();}};\n }\n};})(Drupal,once);"), ('y/js/use.js', "(function(D){D.behaviors.x=D.P.behavior('k','.k',(root, signal) => {"
                      "document.addEventListener('vvj:change',f,{ signal });});})(Drupal);")], None),
    'red-b-c-no-helper': ('x/js/nh.js', "(function(D){D.behaviors.x=D.P.behavior('k','.k',(root, signal) => {"
                          "document.addEventListener('vvj:change',f,{ signal });});})(Drupal);", '(c) Drupal behavior without once()'),
    'red-b-signal-never-aborted': ('x/js/na.js', "(function(D){D.behaviors.x={attach(c){once('x','.x',c);var a=new AbortController();"
                                   "document.addEventListener('keydown',f,{signal:a.signal});},detach(){}};})(Drupal);", '(b) document listener "keydown"'),
    'ok-b-delegated': ('y/js/dg.js', "(function(D){D.Vvj.onState=(root, cb, signal) => {"
                       "document.addEventListener('vvj:change',f,{ signal });};})(Drupal);", None),
    'ok-b-delegated-ctor': ('y/js/dc.js', "(function(D){D.Vvj.S=class S{constructor(strip, { prev = null, signal }) {"
                            "window.addEventListener('resize',f,{ signal });}};})(Drupal);", None),
    'red-b-defaulted-signal': ('x/js/ds.js', "(function(D){D.Vvj.onState=(root, cb, signal = new AbortController().signal) => {"
                               "document.addEventListener('vvj:change',f,{ signal });};})(Drupal);", '(b) document listener "vvj:change"'),
    'red-d-write-read': ('x/js/wr.js', "(function(D){D.behaviors.x={attach(c){once('x','.x',c).forEach(function(e){"
                         "const u = () => {\n e.style.width='1px';\n var w=e.getBoundingClientRect();\n };u();});}};})(Drupal);",
                         'WARN  x/js/wr.js:1  (d) style write then layout read'),
    'ok-d-allowed': ('y/js/da.js', "(function(D){D.behaviors.x={attach(c){once('x','.x',c).forEach(function(e){"
                     "const u = () => { // vvj-motion: allow (d) reads the stylesheet value once\n e.style.width='1px';\n var w=e.getBoundingClientRect();\n };u();});}};})(Drupal);", None),
    'ok-d-allowed-body': ('y/js/db.js', "(function(D){D.behaviors.x={attach(c){once('x','.x',c).forEach(function(e){"
                          "const u = () => {\n // vvj-motion: allow (d) reads the stylesheet value once\n e.style.width='1px';\n var w=e.getBoundingClientRect();\n };u();});}};})(Drupal);", None),
    'red-d-allow-no-reason': ('x/js/dn.js', "(function(D){D.behaviors.x={attach(c){once('x','.x',c).forEach(function(e){"
                              "const u = () => { // vvj-motion: allow (d)\n e.style.width='1px';\n var w=e.getBoundingClientRect();\n };u();});}};})(Drupal);",
                              'WARN  x/js/dn.js:1  (d) style write then layout read'),
    'ok-d-handler': ('y/js/dh.js', "(function(D){D.behaviors.x={attach(c){once('x','.x',c).forEach(function(e){"
                     "e.addEventListener('pointermove', (v) => {\n var b=e.getBoundingClientRect();\n e.style.setProperty('--x', b.left+'px');\n });});}};})(Drupal);", None),
}


def self_test():
    import io
    tmp = tempfile.mkdtemp(prefix='vvj-motion-')
    bad = 0
    try:
        for name, item in SELF.items():
            rel, content, expect = item if len(item) == 3 else (item[0], None, item[1])
            case = os.path.join(tmp, name)
            files = rel if isinstance(rel, list) else [(rel, content)]
            for frel, fcontent in files:
                p = os.path.join(case, frel)
                os.makedirs(os.path.dirname(p), exist_ok=True)
                with open(p, 'w') as fh:
                    fh.write(fcontent)
            rel = files[0][0]
            buf = io.StringIO()
            rc = run([os.path.join(case, rel.split('/')[0])], out=buf)
            txt = buf.getvalue()
            if expect is None:
                ok = rc == 0 and 'FAIL' not in txt and 'WARN' not in txt
            elif expect.startswith('WARN'):
                ok = rc == 0 and expect in txt and run([os.path.join(case, rel.split('/')[0])], strict=True,
                                                       out=io.StringIO()) == 1
            else:
                ok = rc == 1 and expect in txt
            print('%s  %s' % ('ok  ' if ok else 'BAD ', name))
            if not ok:
                bad += 1
                print(txt)
    finally:
        shutil.rmtree(tmp)
    print('self-test: %d of %d cases as expected' % (len(SELF) - bad, len(SELF)))
    return 1 if bad else 0


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('paths', nargs='*')
    ap.add_argument('--strict', action='store_true', help='WARN counts as failure')
    ap.add_argument('--list', action='store_true', help='also print the safe-listed hover rules')
    ap.add_argument('--self-test', action='store_true')
    a = ap.parse_args()
    if a.self_test:
        return self_test()
    paths = a.paths or [os.path.join(HERE, '..', '..', r) for r in REPOS]
    return run(paths, a.strict, a.list)


if __name__ == '__main__':
    sys.exit(main())
