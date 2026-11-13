#!/usr/bin/env python3
"""VVJ cascade check: which CSS declaration wins on a VVJ element, before and after a change.

Static only: reads CSS, *.libraries.yml, Twig, pattern JS and pattern.yml. No site, no DOM, no tests/.
Plan: planning/vvj-cascade-plan.md (lane root), step S0. Modelled on solo/scripts/check-cascade.py.

What it compares. Every VVJ declaration (all 11 repos, admin CSS excluded) is paired with every other
declaration that can reach the same element and sets the same property to a different value: other VVJ
CSS, Solo (theme CSS plus the region colors solo_utilities generates) and Olivero. The winner of each
pair is computed for the working tree and for the VVJ repos at a git revision (default HEAD), extracted
to a temporary directory and deleted afterwards. Solo and Olivero are read from disk for both sides, so
only VVJ edits can change a winner. The baseline is git itself: no snapshot file to drift.

Exit 1 when, against the revision: a pair's winner changed; a Pattern selector over 0,2,0 is new; a new
P4 loss appears (Solo/Olivero must beat VVJ layered CSS, except the vvj.locked layer, whose
!important values must beat every theme); or a new P5 token-ownership violation appears.
Exit 2 on a usage or input error. Exit 0 otherwise.

Winner model (CSS Cascade 5): !important beats normal; normal unlayered beats layered and a later layer
beats an earlier one (reversed for !important); then specificity; then load order: group (module CSS 0,
theme CSS 100, solo_utilities' generated group from ThemeLibraryRegistrar.php), category + weight,
dependency rank (vvj_core, vvj_patterns, format module, pattern), file order in its libraries.yml, line.

Two pairings beyond equal property names (both closed from the S2 flatten run, planning/vvj-s2-result.md):
a lone modifier `.vvj-pattern--X` stands for the root triple `.vvj-pattern.<repo>.vvj-pattern--X` it replaces (the pattern
templates always put them on one element), so it pairs with root-triple rules; and a shorthand pairs with the longhands it
resets (animation / animation-delay, font / font-size, margin / margin-top, inset / top ...), same meet rules.

A third pairing, for a rule whose subject compound is only `:where(tag list)` (the neutral reset in
vvj_patterns/css/vvj-patterns-base.css, `.vvj-pattern :where(h1, p, a ...)`): it has no class token, so it cannot pair by
tokens. It pairs with every Pattern element whose tag (the same tag map) is listed, and a rival that names a tag meets it
only when that tag is listed (logic copied from planning/scripts/s1b-reset-model.py, not imported). Pseudo-element rivals
are left out (the reset cannot reach them). Only a prefix of `.vvj-pattern` classes is modelled; others are listed as
"tag-subject rules not modelled". A subject written `:where(<one selector>)` is read as that selector (it had no tokens).
When the baseline lacks such a rule, its absence means every rival wins there, so the pairs it adds count as changed
winners where the rule now wins (and the reverse when it is removed).

Heuristics (needs checking on a page): media queries and DOM ancestry are ignored, so a pair may never
meet; an element is the subject's last compound. A theme rule meets a VVJ rule when every token of the
theme subject (classes, ids, tag) is on the VVJ element; tags come from Twig, pattern JS and the
`needs` lines of pattern.yml. Two rules from different Pattern files never meet (one modifier each).

Baseline: `--rev REV` (git, default HEAD) or `--before DIR`, a directory laid out like the lane (DIR/vvj_core, DIR/vvja ...),
for when the baseline is not a commit. Not both.

Usage: python3 -I check-vvj-cascade.py [--lane DIR] [--vvj DIR] [--olivero DIR] [--rev REV | --before DIR] [--list]
"""
import argparse, collections, os, re, subprocess, sys, tarfile, tempfile, shutil

HERE = os.path.dirname(os.path.abspath(__file__))
REPOS = ['vvj_core', 'vvja', 'vvjb', 'vvjc', 'vvjf', 'vvjh', 'vvjl', 'vvjp', 'vvjr', 'vvjs', 'vvjt']
CAT = {'base': -200, 'layout': -100, 'component': 0, 'state': 100, 'theme': 200}
CAP = (0, 2, 0)
VVJ_SKIP = ('-admin.css', 'vvj-ai-brief.css')
SOLO_ADMIN_LIBS = {'solo-form-theme-settings', 'solo-node-edit-form', 'solo-debug'}
SOLO_ADMIN_FILES = ('css/theme/solo-ckeditor', 'css/debug/', 'css/components/solo-layout-builder.css')
# Layers whose !important values must beat every theme (vvj-base.css): a theme losing to them is the
# point, not a P4 loss.
LOCKED_LAYERS = {'vvj.locked'}
# Custom property namespaces and their one owner (contract P5; same table as solo/scripts/check-cascade.py).
OWNERS = {'--r-': {'solo', 'generated'}, '--vvj-': {'vvj'}, '--pb-': {'paragraphs_bundles'},
          '--selectify-': {'selectify', 'solo'}, '--uk-': {'utilikit'}, '--w3-': {'w3css_paragraphs'}}
WALK_SKIP = ('tests', 'node_modules', '.git', 'vendor', 'web')


# ---------------------------------------------------------------- CSS parser
def strip_comments(t):
    out = []; i = 0; n = len(t)
    while i < n:
        if t.startswith('/*', i):
            j = t.find('*/', i + 2); j = n if j < 0 else j + 2
            out.append(''.join(c if c == '\n' else ' ' for c in t[i:j])); i = j
        elif t[i] in '"\'':
            q = t[i]; j = i + 1
            while j < n and t[j] != q:
                j += 2 if t[j] == '\\' else 1
            out.append(t[i:j + 1]); i = j + 1
        else:
            out.append(t[i]); i += 1
    return ''.join(out)


def split_top(s, ch):
    parts = []; depth = 0; cur = []; q = None
    for c in s:
        if q:
            cur.append(c)
            if c == q: q = None
            continue
        if c in '"\'': q = c
        elif c in '([': depth += 1
        elif c in ')]': depth -= 1
        if c == ch and depth == 0: parts.append(''.join(cur)); cur = []
        else: cur.append(c)
    parts.append(''.join(cur)); return parts


# A subject compound that is only `:where(tag, tag ...)`; group 1 is what precedes it, group 2 the tag list.
TAG_SUBJECT = re.compile(r'^(?:(.*\S)\s+)?:where\(([a-zA-Z][\w-]*(?:\s*,\s*[a-zA-Z][\w-]*)*)\)$')
PSEUDO_ELEMENT = re.compile(r'::|:(?:before|after|first-line|first-letter)\b')
OPAQUE = ('@keyframes', '@-webkit-keyframes', '@font-face', '@page', '@property', '@counter-style')


def parse(text):
    """Return [(line, at-rule chain, selector, property, value, important)]. CSS nesting is not read."""
    t = strip_comments(text); rules = []; stack = []; buf = []; i = 0; n = len(t); line = 1; q = None; paren = 0
    while i < n:
        c = t[i]
        if c == '\n': line += 1
        if q:
            buf.append(c)
            if c == '\\' and i + 1 < n: buf.append(t[i + 1]); i += 2; continue
            if c == q: q = None
            i += 1; continue
        if c in '"\'': q = c; buf.append(c); i += 1; continue
        if c == '(': paren += 1
        if c == ')': paren -= 1
        if c == '{' and paren == 0:
            raw = ''.join(buf); buf = []
            parent = next((s for s in reversed(stack) if s[0] == 'rule'), None)
            if stack and stack[-1][0] == 'rule':
                # CSS nesting (Olivero): declarations before the nested rule belong to the parent.
                cut = max(raw.rfind(';'), raw.rfind('}'))
                stack[-1][3].append(raw[:cut + 1]); raw = raw[cut + 1:]
            pre = ' '.join(raw.split())
            if stack and stack[-1][0] == 'opaque' or pre.lower().startswith(OPAQUE): stack.append(('opaque',))
            elif pre.startswith('@'): stack.append(('at', pre))
            else:
                if parent:
                    pre = ', '.join(n.strip().replace('&', p.strip()) if '&' in n else p.strip() + ' ' + n.strip()
                                    for p in split_top(parent[1], ',') for n in split_top(pre, ','))
                stack.append(('rule', pre, line, []))
            i += 1; continue
        if c == '}' and paren == 0:
            top = stack.pop() if stack else ('none',)
            body = (''.join(top[3]) if top[0] == 'rule' else '') + ''.join(buf); buf = []
            if top[0] == 'rule':
                ctx = tuple(s[1] for s in stack if s[0] == 'at')
                for d in split_top(body, ';'):
                    if ':' not in d: continue
                    p, v = d.split(':', 1); p = p.strip().lower(); v = ' '.join(v.split())
                    if not p or '{' in p: continue
                    imp = bool(re.search(r'!\s*important\s*$', v, re.I))
                    v = re.sub(r'\s*!\s*important\s*$', '', v, flags=re.I)
                    for s in split_top(top[1], ','):
                        rules.append((top[2], ctx, ' '.join(s.split()), p, v, imp))
            i += 1; continue
        if c == ';' and paren == 0 and not (stack and stack[-1][0] == 'rule'):
            buf = []; i += 1; continue
        buf.append(c); i += 1
    return rules


def specificity(sel):
    s = re.sub(r':where\((?:[^()]|\([^()]*\))*\)', '', sel); extra = [0, 0, 0]
    def mx(m):
        return '\x00%d,%d,%d\x00' % max(specificity(a) for a in split_top(m.group(2), ','))
    while True:
        s2 = re.sub(r':(is|not|has|matches)\(((?:[^()]|\([^()]*\))*)\)', mx, s)
        if s2 == s: break
        s = s2
    for m in re.finditer(r'\x00(\d+),(\d+),(\d+)\x00', s):
        for k in range(3): extra[k] += int(m.group(k + 1))
    s = re.sub(r'\x00[\d,]+\x00', '', s)
    s = re.sub(r'\[[^\]]*\]', ' .x ', s)
    a = len(re.findall(r'#[\w-]+', s))
    pe = len(re.findall(r'::[\w-]+|:(?:before|after|first-line|first-letter)\b', s))
    s = re.sub(r'::[\w-]+(\([^)]*\))?|:(?:before|after|first-line|first-letter)\b', ' ', s)
    b = len(re.findall(r'\.[\w-]+', s)) + len(re.findall(r':[\w-]+', s))
    s = re.sub(r'#[\w-]+|\.[\w-]+|:[\w-]+(\([^)]*\))?', ' ', s)
    c = len(re.findall(r'(?<![\w-])[a-zA-Z][\w-]*', s)) + pe
    return (a + extra[0], b + extra[1], c + extra[2])


def where_args(last):
    """The argument of a compound that is exactly `:where(...)` or `:is(...)`, else None."""
    m = re.match(r':(?:where|is)\(', last)
    if not m: return None
    depth = 0
    for i in range(m.end() - 1, len(last)):
        if last[i] == '(': depth += 1
        elif last[i] == ')':
            depth -= 1
            if depth == 0: return last[m.end():i] if i == len(last) - 1 else None
    return None


def last_compound(sel):
    """(tokens, pseudo) of the subject: classes, ids and 'tag:x'; pseudo-classes and elements kept apart."""
    last = split_top(re.sub(r'\s*([>+~])\s*', ' ', sel.strip()), ' ')[-1]
    inner = where_args(last)
    # `:where(<one selector>)` is transparent for the subject; a tag list is the reset (see TAG_SUBJECT), left tokenless.
    if inner is not None and inner.strip() and len(split_top(inner, ',')) == 1 and not re.fullmatch(r'[a-zA-Z][\w-]*', inner.strip()):
        return last_compound(inner)
    pseudo = ''.join(sorted(m.group(0) for m in re.finditer(r'::?[\w-]+(\((?:[^()]|\([^()]*\))*\))?', last)))
    bare = re.sub(r'::?[\w-]+(\((?:[^()]|\([^()]*\))*\))?|\[[^\]]*\]', '', last)
    toks = set(re.findall(r'[.#][\w-]+', bare))
    m = re.match(r'([a-zA-Z][\w-]*)', bare)
    if m: toks.add('tag:' + m.group(1).lower())
    return frozenset(toks), pseudo


# ---------------------------------------------------------------- libraries
def libraries(path):
    """file -> (category + weight, file index, library name) from one *.libraries.yml."""
    o = {}; lib = cat = None
    for i, ln in enumerate(open(path, encoding='utf-8')):
        m = re.match(r'^([\w.-]+):\s*$', ln)
        if m: lib = m.group(1); cat = None; continue
        m = re.match(r'^    (base|layout|component|state|theme):\s*$', ln)
        if m: cat = m.group(1); continue
        if re.match(r'^    \S', ln): cat = None; continue
        m = re.match(r'^      ([\w./-]+\.css):\s*\{(.*)\}', ln)
        if m and cat:
            w = re.search(r'weight:\s*(-?\d+)', m.group(2))
            o.setdefault(m.group(1), (CAT[cat] + (int(w.group(1)) if w else 0), i, lib))
    return o


def walk(root, keep):
    for d, ds, fs in os.walk(root, followlinks=True):
        ds[:] = sorted(x for x in ds if x not in WALK_SKIP)
        for fn in sorted(fs):
            if keep(fn): yield os.path.join(d, fn)


def decl(src, f, ln, ctx, sel, p, v, imp, order, **extra):
    layers = [c[7:].strip() for c in ctx if c.startswith('@layer ')]
    toks, pseudo = last_compound(sel)
    d = dict(src=src, f=f, ln=ln, sel=sel, p=p, v=v, imp=imp, layer='.'.join(layers) or None,
             media=tuple(c for c in ctx if not c.startswith('@layer ')), sp=specificity(sel), o=order + (ln,),
             toks=toks, pseudo=pseudo)
    d.update(extra); return d


# ---------------------------------------------------------------- VVJ
def vvj_decls(vroot, repo):
    """Declarations from every CSS file a VVJ libraries.yml lists (admin CSS excluded)."""
    root = os.path.join(vroot, repo); out = []
    for lib_file in walk(root, lambda fn: fn.endswith('.libraries.yml')):
        base = os.path.relpath(os.path.dirname(lib_file), root)
        for f, (w, idx, lib) in libraries(lib_file).items():
            rel = os.path.normpath(os.path.join(base, f))
            fp = os.path.join(root, rel)
            if rel.endswith(VVJ_SKIP) or 'admin' in lib or not os.path.exists(fp): continue
            parts = rel.split(os.sep)
            pattern = 'patterns' in parts or rel.startswith(os.path.join('modules', 'vvj_patterns', 'css'))
            pname = parts[parts.index('patterns') + 1] if 'patterns' in parts and len(parts) > parts.index('patterns') + 2 else None
            rank = 0 if repo == 'vvj_core' and not rel.startswith('modules') else 1 if rel.startswith(os.path.join('modules', 'vvj_patterns')) else 3 if pattern else 2
            for r in parse(open(fp, encoding='utf-8', errors='replace').read()):
                d = decl('vvj', repo + '/' + rel, *r, order=(0, w, rank, idx), repo=repo,
                         kind='pattern' if pattern else 'module', pname=pname and repo + ':' + pname)
                if any(t.startswith('.vvj-pattern--') for t in d['toks']):
                    d['mods'] = frozenset(t for t in d['toks'] if t.startswith('.vvj-pattern--'))
                    d['toks'] = d['toks'] | {'.vvj-pattern', '.' + repo}
                m = TAG_SUBJECT.match(d['sel'])
                if m:
                    d['pseudo'] = ''
                    if re.fullmatch(r'(?:\.vvj-pattern)+', m.group(1) or ''):
                        d['rtags'] = frozenset('tag:' + t.strip().lower() for t in m.group(2).split(','))
                    else: d['rtags_skipped'] = True
                out.append(d)
    return out


def layer_order(vroot):
    """Layer names in order of first appearance across vvj_core's CSS (statements and blocks)."""
    names = []
    for fp in walk(os.path.join(vroot, 'vvj_core'), lambda fn: fn.endswith('.css')):
        for m in re.finditer(r'@layer\s+([^{;]+)[{;]', strip_comments(open(fp, encoding='utf-8').read())):
            for n in m.group(1).split(','):
                n = n.strip()
                if n and n not in names: names.append(n)
    return names


HTML_CLASS = re.compile(r'<([a-zA-Z][\w-]*)\b[^<>]*?\bclass\s*=\s*"([^"]*)"', re.S)
CREATE = re.compile(r"createElement\(\s*['\"]([a-zA-Z][\w-]*)['\"]\s*\)(.{0,400}?)(?:className\s*=\s*['\"`]([^'\"`]+)|classList\.add\(([^)]*)\))", re.S)
NEEDS = re.compile(r'\b(a|p|div|span|button|img|ul|ol|li|h[1-6]|figure|figcaption|time|blockquote|strong|em|small|nav|section|article|header|footer)((?:\.[\w-]+)+)')


def class_words(raw):
    """Literal class words of a class attribute; Twig output kept only from its quoted strings."""
    raw = re.sub(r'\{%.*?%\}', ' ', raw, flags=re.S)
    raw = re.sub(r'\{\{(.*?)\}\}', lambda m: ' '.join(re.findall(r"'([^']*)'", m.group(1))), raw, flags=re.S)
    return [w for w in raw.split() if re.match(r'^[\w-]+$', w)]


def tag_map(vroot):
    """class -> {'tags': set, 'co': set of classes seen on the same element}."""
    m = collections.defaultdict(lambda: {'tags': set(), 'co': set()})
    def add(tag, classes):
        for c in classes:
            m[c]['tags'].add(tag.lower()); m[c]['co'].update(classes)
    for repo in REPOS:
        root = os.path.join(vroot, repo)
        if not os.path.isdir(root): continue
        for fp in walk(root, lambda fn: fn.endswith(('.twig', '.js', 'pattern.yml'))):
            t = open(fp, encoding='utf-8', errors='replace').read()
            for h in HTML_CLASS.finditer(t): add(h.group(1), class_words(h.group(2)))
            if fp.endswith('.twig'):
                # Wrapper: `{% set classes = [...] %}` then `<tag {{ attributes.addClass(classes) }}`.
                s = re.search(r"\{%\s*set classes = \[(.*?)\]\s*%\}", t, re.S)
                w = re.search(r'<([a-zA-Z][\w-]*)\s+\{\{\s*attributes\.addClass\(classes\)', t)
                if s and w: add(w.group(1), [x for x in re.findall(r"'([\w-]+)'", s.group(1))])
            if fp.endswith('.js'):
                for c in CREATE.finditer(t):
                    words = c.group(3) or ' '.join(re.findall(r"['\"]([\w-]+)['\"]", c.group(4) or ''))
                    add(c.group(1), [w for w in words.split() if re.match(r'^[\w-]+$', w)])
            if fp.endswith('pattern.yml'):
                for n in NEEDS.finditer(t): add(n.group(1), n.group(2).strip('.').split('.'))
    return m


def element(d, tmap):
    """Tokens that are on the element a VVJ declaration styles."""
    e = set(d['toks'])
    for t in d['toks']:
        if t.startswith('.vvj-pattern--'): e.update({'.vvj-pattern', '.' + d['repo']})
    for t in list(e):
        if t.startswith('.') and t[1:] in tmap:
            e.update('tag:' + x for x in tmap[t[1:]]['tags'])
            e.update('.' + x for x in tmap[t[1:]]['co'])
    return frozenset(e)


# ---------------------------------------------------------------- themes
def solo_decls(lane):
    solo = os.path.join(lane, 'solo'); out = []
    lib_file = os.path.join(solo, 'solo.libraries.yml')
    if not os.path.exists(lib_file): return out
    for f, (w, idx, lib) in libraries(lib_file).items():
        if lib in SOLO_ADMIN_LIBS or f.startswith(SOLO_ADMIN_FILES): continue
        fp = os.path.join(solo, f)
        if not os.path.exists(fp): continue
        for r in parse(open(fp, encoding='utf-8').read()):
            out.append(decl('solo', 'solo/' + f, *r, order=(100, w, 0, idx)))
    reg_path = os.path.join(lane, 'solo_utilities/src/Css/ThemeLibraryRegistrar.php')
    css_path = os.path.join(lane, 'solo_utilities/src/Css/ThemeCssBuilder.php')
    if os.path.exists(reg_path) and os.path.exists(css_path):
        reg = open(reg_path, encoding='utf-8').read()
        num = lambda name: int(re.search(name + r' = (-?\d+);', reg).group(1))
        group, w = num('GROUP'), num('SETTINGS_WEIGHT') + CAT['theme']
        php = open(css_path, encoding='utf-8').read()
        regions = re.findall(r"'([\w]+)' => '([^']+)'", re.search(r'REGION_SELECTORS = \[(.*?)\];', php, re.S).group(1))
        props = re.findall(r"'[\w]+' => '(--[\w-]+)'", re.search(r'COLOR_PROPERTIES = \[(.*?)\];', php, re.S).group(1))
        for n, (name, sel) in enumerate(regions):
            for p in props:
                out.append(decl('generated', 'generated:' + name, n, (), sel, p, '#123456', False, order=(group, w, 0, 0)))
    return out


def olivero_decls(odir):
    out = []; lib_file = os.path.join(odir, 'olivero.libraries.yml')
    if not os.path.exists(lib_file): return out
    for f, (w, idx, lib) in libraries(lib_file).items():
        fp = os.path.join(odir, f)
        if not os.path.exists(fp): continue
        for r in parse(open(fp, encoding='utf-8').read()):
            out.append(decl('olivero', 'olivero/' + f, *r, order=(100, w, 0, idx)))
    return out


# ---------------------------------------------------------------- cascade
def rank(d, layers):
    li = (layers.index(d['layer']) + 1 if d['layer'] in layers else len(layers) + 1) if d['layer'] else 0
    if d['imp']:
        return (1, 1 if d['layer'] else 0, -li, d['sp'], d['o'])
    return (0, 0 if d['layer'] else 1, li, d['sp'], d['o'])


def stable_key(d):
    """Identity that survives a selector rewrite (S2-S5): file, media, subject tokens minus the root triple, value.
    !important is not part of a VVJ key, so removing it pairs with its baseline and a flipped winner shows as changed."""
    toks = d['toks']
    if d.get('rtags'):
        return (d['f'], d['media'], ('<tags>',) + tuple(sorted(d['rtags'])), '', d['p'], d['v'])
    if d['src'] == 'vvj':
        mods = d.get('mods', frozenset())
        rest = {t for t in toks if not (t == '.vvj-pattern' or t in mods or t == '.' + d['repo'])}
        return (d['f'], d['media'], tuple(sorted(rest or mods or toks)), d['pseudo'], d['p'], d['v'])
    return (d['f'], d['media'], d['sel'], d['p'], d['v'], d['imp'])


def vvj_meet(a, b):
    if a['pname'] and b['pname'] and a['pname'] != b['pname']: return False
    ma = {t for t in a['toks'] if t.startswith('.vvj-pattern--')}; mb = {t for t in b['toks'] if t.startswith('.vvj-pattern--')}
    if ma and mb and ma != mb: return False
    if a['repo'] != b['repo'] and 'vvj_core' not in (a['repo'], b['repo']): return False
    return bool({t for t in a['toks'] if t[0] in '.#'} & {t for t in b['toks'] if t[0] in '.#'})


SPECIAL = {'inset': {'top', 'right', 'bottom', 'left'}, 'place-items': {'align-items', 'justify-items'},
           'place-content': {'align-content', 'justify-content'}, 'place-self': {'align-self', 'justify-self'},
           'gap': {'row-gap', 'column-gap'}, 'grid-gap': {'row-gap', 'column-gap', 'grid-row-gap', 'grid-column-gap'},
           'overflow': {'overflow-x', 'overflow-y'}, 'flex-flow': {'flex-direction', 'flex-wrap'}}


def covers(s, l):
    """True when shorthand s resets longhand l."""
    if s == l: return False
    if l in SPECIAL.get(s, ()): return True
    if s == 'border' and 'radius' in l: return False
    if s == 'color' or s == 'flex' and l not in ('flex-grow', 'flex-shrink', 'flex-basis'): return False
    if s == 'grid' and re.match(r'grid-(column|row|area|gap)', l): return False
    return l.startswith(s + '-')


def analyse(vroot, themes, layers_hint=None):
    vvj = []
    for repo in REPOS:
        if os.path.isdir(os.path.join(vroot, repo)): vvj += vvj_decls(vroot, repo)
    layers = layer_order(vroot) if os.path.isdir(os.path.join(vroot, 'vvj_core')) else (layers_hint or [])
    tmap = tag_map(vroot)
    for d in vvj: d['el'] = element(d, tmap)
    outcomes = collections.defaultdict(set); p4 = set()
    def record(a, b):
        ka, kb = stable_key(a), stable_key(b)
        a_wins = win = rank(a, layers) > rank(b, layers)
        if ka > kb: ka, kb, win = kb, ka, not win
        outcomes[(ka, kb)].add('first' if win else 'second')
        return a_wins
    # VVJ against VVJ: same property, a shared class on the subject.
    by = collections.defaultdict(list)
    for d in vvj:
        for t in d['toks']:
            if t[0] in '.#': by[(d['p'], t)].append(d)
    seen = set()
    for a in vvj:
        for t in a['toks']:
            for b in by.get((a['p'], t), ()):
                k = (id(a), id(b)) if id(a) < id(b) else (id(b), id(a))
                if a is b or k in seen or a['v'] == b['v'] or not vvj_meet(a, b): continue
                seen.add(k); record(a, b)
    # Themes against VVJ: every token of the theme subject is on the VVJ element.
    tby = collections.defaultdict(list)
    for d in themes:
        if d['toks']: tby[(d['p'], min(d['toks']))].append(d)
    for v in vvj:
        cand = set()
        for t in v['el']:
            for th in tby.get((v['p'], t), ()):
                if id(th) not in cand and th['v'] != v['v'] and th['toks'] <= v['el']:
                    cand.add(id(th))
                    if not record(th, v) and v['layer'] and v['layer'] not in LOCKED_LAYERS:
                        p4.add('%s:%s %s {%s} | %s:%s %s' % (th['f'], th['ln'], th['sel'], th['p'], v['f'], v['ln'], v['sel']))
    # Shorthand against longhand (animation vs animation-delay ...): same meet rules, values not compared.
    props = sorted({d['p'] for d in vvj} | {d['p'] for d in themes})
    shorts = collections.defaultdict(list); longs = collections.defaultdict(list)
    for s_ in props:
        for l_ in props:
            if covers(s_, l_): shorts[l_].append(s_); longs[s_].append(l_)
    for a in vvj:
        for s_ in shorts.get(a['p'], ()):
            for t in a['toks']:
                for b in by.get((s_, t), ()):
                    if vvj_meet(a, b): record(a, b)
    for v in vvj:
        for other in shorts.get(v['p'], []) + longs.get(v['p'], []):
            for t in v['el']:
                for th in tby.get((other, t), ()):
                    if th['toks'] <= v['el']: record(th, v)
    # Tag-subject rules (the reset): paired with every Pattern element of a listed tag (copied from s1b-reset-model.py).
    resets = [d for d in vvj if d.get('rtags')]
    skipped = sorted({(d['f'], d['sel']) for d in vvj if d.get('rtags_skipped')})
    if resets:
        related = lambda p, q: p == q or covers(p, q) or covers(q, p)
        own = [d for d in vvj if not d.get('rtags')]
        elements = {d['el'] for d in own if d['kind'] == 'pattern' and any(t.startswith('tag:') for t in d['el'])}
        by_tok = collections.defaultdict(list)
        for d in own:
            for t in d['toks']: by_tok[t].append(d)
        tany = collections.defaultdict(list)
        for d in themes:
            if d['toks']: tany[min(d['toks'])].append(d)
        rpairs = {}
        for el in sorted(elements, key=sorted):
            rs = [r for r in resets if r['rtags'] & el]
            if not rs: continue
            pats = {t for t in el if t.startswith('.vvj-pattern--')}
            rivals = {id(d): d for t in el for d in by_tok.get(t, ()) if d['toks'] <= el
                      and not ({x for x in d['toks'] if x.startswith('.vvj-pattern--')} - pats)}
            rivals.update({id(d): d for t in el for d in tany.get(t, ()) if d['toks'] <= el})
            for r in rs:
                for d in rivals.values():
                    if PSEUDO_ELEMENT.search(d['pseudo']) or not related(d['p'], r['p']): continue
                    if d['p'] == r['p'] and d['v'] == r['v']: continue
                    # A rival whose subject names a tag meets the reset only when that tag is one the reset reaches.
                    if any(t.startswith('tag:') and t not in r['rtags'] for t in d['toks']): continue
                    rpairs[(id(r), id(d))] = (r, d)
        for r, d in rpairs.values(): record(r, d)
    # P5: one owner per custom-property namespace.
    p5 = set()
    for d in vvj + themes:
        owner = 'vvj' if d['src'] == 'vvj' else d['src']
        for ns, allowed in OWNERS.items():
            if d['p'].startswith(ns) and owner not in allowed:
                p5.add('%s %s sets %s' % (d['f'], d['sel'], d['p']))
    # Specificity per repo, Pattern and module CSS counted per selector (file, line, selector).
    sels = {}
    for d in vvj: sels[(d['repo'], d['kind'], d['f'], d['ln'], d['sel'])] = d['sp']
    counts = collections.defaultdict(lambda: [0, 0, 0, 0])
    over = set()
    for (repo, kind, f, ln, sel), sp in sels.items():
        c = counts[repo]; i = 0 if kind == 'pattern' else 2
        c[i] += 1
        if sp > CAP:
            c[i + 1] += 1
            if kind == 'pattern': over.add((f, sel))
    mapped = {t for d in vvj if d['kind'] == 'pattern' for t in d['toks'] if t[0] == '.' and t[1:] in tmap}
    allc = {t for d in vvj if d['kind'] == 'pattern' for t in d['toks'] if t[0] == '.'}
    keys = {stable_key(d) for d in vvj + themes}
    return dict(n=len(vvj), keys=keys, rkeys={stable_key(d) for d in resets}, skipped=skipped, outcomes=outcomes, p4=p4, p5=p5, counts=counts, over=over, layers=layers,
                tagged=(len(mapped), len(allc)))


def snapshot(vroot, rev, dest):
    """Extract each VVJ repo at rev into dest (git archive). Returns repos extracted."""
    got = []
    for repo in REPOS:
        src = os.path.join(vroot, repo)
        if not os.path.isdir(os.path.join(src, '.git')): continue
        tar = os.path.join(dest, repo + '.tar')
        r = subprocess.run(['git', '-C', src, 'archive', '--format=tar', '-o', tar, rev], capture_output=True, text=True)
        if r.returncode != 0:
            sys.stderr.write('git archive %s %s failed: %s\n' % (repo, rev, r.stderr.strip())); sys.exit(2)
        with tarfile.open(tar) as tf: tf.extractall(os.path.join(dest, repo), filter='data')
        os.unlink(tar); got.append(repo)
    return got


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    lane_default = os.path.dirname(os.path.dirname(HERE))
    ap.add_argument('--lane', default=lane_default, help='directory holding solo/ and solo_utilities/ (optional)')
    ap.add_argument('--vvj', default=None, help='directory holding the VVJ repos (default: --lane)')
    ap.add_argument('--olivero', default=os.path.expanduser('~/Sites/d11/web/core/themes/olivero'))
    grp = ap.add_mutually_exclusive_group()
    grp.add_argument('--rev', default=None, help='git revision of the VVJ repos to compare against (default HEAD)')
    grp.add_argument('--before', default=None, help='directory laid out like the lane (DIR/vvj_core, DIR/vvja ...) to compare against')
    ap.add_argument('--list', action='store_true', help='print every change')
    a = ap.parse_args()
    vroot = a.vvj or a.lane
    if not os.path.isdir(os.path.join(vroot, 'vvj_core')) and not any(os.path.isdir(os.path.join(vroot, r)) for r in REPOS):
        sys.stderr.write('no VVJ repo under %s\n' % vroot); return 2
    solo = solo_decls(a.lane); oli = olivero_decls(a.olivero)
    print('themes: solo+generated %d decls%s, olivero %d decls%s' % (
        len(solo), '' if solo else ' (not found, skipped)', len(oli), '' if oli else ' (not found, skipped)'))
    themes = solo + oli
    cur = analyse(vroot, themes)
    if a.before:
        bdir = os.path.abspath(a.before); label = 'dir ' + a.before
        repos = [r for r in REPOS if os.path.isdir(os.path.join(bdir, r))]
        if not repos:
            sys.stderr.write('no VVJ repo under --before %s\n' % a.before); return 2
        base = analyse(bdir, themes, cur['layers'])
    else:
        rev = a.rev or 'HEAD'; label = rev
        tmp = tempfile.mkdtemp(prefix='vvj-cascade-')
        try:
            repos = snapshot(vroot, rev, tmp)
            base = analyse(tmp, themes, cur['layers'])
        finally:
            shutil.rmtree(tmp, ignore_errors=True)
    print('vvj: %d decls in %d repos; layers %s; pattern classes with a known tag %d/%d; base %s (%d repos)' % (
        cur['n'], len(cur['counts']), ', '.join(cur['layers']) or '-', cur['tagged'][0], cur['tagged'][1], label, len(repos)))
    print('%-9s %9s %12s %9s %12s' % ('repo', 'pattern', 'over 0,2,0', 'module', 'over 0,2,0'))
    tot = [0, 0, 0, 0]
    for repo in REPOS:
        if repo in cur['counts']:
            c = cur['counts'][repo]; tot = [x + y for x, y in zip(tot, c)]
            print('%-9s %9d %12d %9d %12d' % (repo, *c))
    print('%-9s %9d %12d %9d %12d' % ('total', *tot))
    changed = sorted(k for k in cur['outcomes'] if k in base['outcomes'] and cur['outcomes'][k] != base['outcomes'][k])
    added = [k for k in cur['outcomes'] if k not in base['outcomes']]
    gone = [k for k in base['outcomes'] if k not in cur['outcomes']]
    # A tag-subject rule on one side only: where it is absent every rival wins, so a pair it adds (or drops) is a changed
    # winner when the rule wins on the side that has it. Both keys must exist on the other side.
    def implied(pairs, have, other):
        out = []
        for k in pairs:
            for i, (mine, rival) in enumerate((k, k[::-1])):
                if mine in have['rkeys'] and mine not in other['keys'] and rival in other['keys']:
                    if have['outcomes'][k] != {'second' if i == 0 else 'first'}: out.append(k)
                    break
        return out
    via = sorted(set(implied(added, cur, base)) | set(implied(gone, base, cur)))
    added = [k for k in added if k not in via]; gone = [k for k in gone if k not in via]
    changed = sorted(changed + via)
    new_over = sorted(cur['over'] - base['over'])
    new_p4 = sorted(cur['p4'] - base['p4']); new_p5 = sorted(cur['p5'] - base['p5'])
    rows = [('pairs compared', len(cur['outcomes']), None), ('winners changed vs ' + label, len(changed), changed),
            ('pairs added (info)', len(added), None), ('pairs gone (info)', len(gone), None),
            ('new Pattern selectors over 0,2,0', len(new_over), new_over),
            ('P4 theme beats vvj layers: losing', len(cur['p4']), None), ('P4 new losses', len(new_p4), new_p4),
            ('P5 token owner violations', len(cur['p5']), None), ('P5 new violations', len(new_p5), new_p5),
            ('tag-subject rules modelled (info)', len(cur['rkeys']), None),
            ('  of the changed: via a tag-subject rule', len(via), None),
            ('tag-subject rules not modelled (info)', len(cur['skipped']), None)]
    status = 0
    for name, n, items in rows:
        print('%-36s %7d' % (name, n))
        if items: status = 1
        if a.list and items:
            for x in items[:200]: print('    %s' % (x,))
    if a.list and cur['skipped']:
        for x in cur['skipped'][:50]: print('    not modelled %s' % (x,))
    if a.list and (added or gone):
        for k in sorted(added)[:200]: print('    added %s' % (k,))
        for k in sorted(gone)[:200]: print('    gone  %s' % (k,))
    print('RESULT: %s' % ('FAIL' if status else 'PASS'))
    return status


if __name__ == '__main__':
    sys.exit(main())
