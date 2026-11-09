#!/usr/bin/env python3
"""Static check of the VVJ Pattern rules that can be read from the files (no site, no browser).

Reads a lane directory holding vvj_core and the VVJ format modules (vvja ... vvjt). The rule numbers are the ones
in the Pattern rules list. Pattern CSS is every CSS file under <module>/patterns/ except bridge/, plus the shared
vvj_patterns base.

  R4   no colour literal in Pattern CSS outside the shared token block (hex, rgb, hsl, oklch).
  R5   no theme markup or variables: every var(--x) is one VVJ itself declares or sets; no theme class in CSS or JS.
  R9   pattern.yml states `stage: open|boxed` and `ground: dark|light`.
  R12  pattern.yml states `serves:` with 2 to 4 known kinds of site.
  R15  pattern.yml states `placement: outer-margin|inner-padding|both|neither`.
  R16  spacing, radius, font size, shadow and motion read a --vvj-* variable with a fallback, at the top level of the
       value. Values inside clamp(), min(), max() and calc() are R18's concern. Exempt: 0, hairlines up to 1.5px, %,
       em, timings under 100ms (staggers) and from 2s (loops).
  R17  an editor's settings win: no !important outside reduced-motion except IMPORTANT_OK (each with its reason), and
       no Pattern CSS redeclares a custom property the templates set from a Views option.
  R18  a size that scales with the screen or region has a floor or ceiling (clamp, min, max, min-/max- property).

Exit 1 with path:line on a failure, and when nothing was checked.
Usage: check-vvj-rules.py [--lane DIR] | --self-test
"""
import argparse, glob, os, re, sys, tempfile

MODULES = "vvja vvjb vvjc vvjf vvjh vvjl vvjp vvjr vvjs vvjt".split()
SHARED_BASE = "vvj_core/modules/vvj_patterns/css/vvj-patterns-base.css"
KINDS = set("associations corporate creative cultural events government healthcare nonprofit publication saas studio university".split())
SPACE = set("""gap row-gap column-gap padding margin padding-block padding-inline margin-block margin-inline
padding-top padding-bottom padding-left padding-right margin-top margin-bottom margin-left margin-right
margin-block-start margin-block-end margin-inline-start margin-inline-end
padding-block-start padding-block-end padding-inline-start padding-inline-end""".split())
MOTION = set("transition transition-duration transition-delay animation animation-duration animation-delay".split())
EASES = {"ease", "ease-out", "ease-in", "ease-in-out"}
R18_PROPS = SPACE | set("width height inline-size block-size flex-basis font-size top left right bottom inset".split())
SCALING = re.compile(r"[\d.]\s*(?:vw|vh|svh|dvh|lvh|vmin|vmax|cqi|cqw|cqh)\b")
THEME_CLASS = re.compile(r"\.(solo-[\w-]+|olivero[\w-]*|claro[\w-]*|apex-[\w-]+|region-[\w-]+|layout-[\w-]+|path-[\w-]+|page-node[\w-]*)")
FONT_LITERAL = re.compile(r"\b(?:ui-monospace|monospace|menlo|consolas|sans-serif|serif|system-ui|georgia|arial|helvetica)\b", re.I)
COLOUR = re.compile(r"#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?|oklch|oklab)\(")

# (file name, property): why !important is right there. None of these is a Views option.
IMPORTANT_OK = {
    ("vvjl-pattern-base.css", "width"): "vvjl.css forces the lightbox content width with !important",
    ("vvjl-pattern-base.css", "margin"): "vvjl.css forces the lightbox content margin with !important",
    ("vvjl-pattern-base.css", "padding"): "vvjl.css forces the lightbox content padding with !important",
    ("vvjl-sheet.css", "padding"): "same vvjl.css lightbox rule",
    ("vvjb-swatch.css", "margin"): "vvjb.css forces the dots margin with !important",
    ("vvjb-nightstory.css", "margin"): "vvjb.css forces the dots margin with !important",
    ("vvjb-switchboard.css", "margin"): "vvjb.css forces the dots margin with !important",
    ("vvjb-switchboard.css", "padding"): "vvjb.css forces the dots padding with !important",
    ("vvjs-ledger.css", "height"): "the carousel script writes inline heights; the rows must not move",
    ("vvjs-ledger.css", "position"): "the carousel script writes inline positions; the rows must not move",
    ("vvjs-ledger.css", "display"): "the carousel script hides slides inline; the rows stay in the grid",
    ("vvjs-deck.css", "height"): "vvjs.css forces navigation dots to 10px with !important",
    ("vvjs-deck.css", "margin"): "vvjs.css forces navigation dots with !important",
    ("vvjh-pattern-base.css", "min-height"): "stacked layout below the reveal breakpoint",
    ("vvjh-pattern-base.css", "max-width"): "stacked layout below the reveal breakpoint",
    ("vvjh-pattern-base.css", "transition"): "stacked layout below the reveal breakpoint",
    ("vvjh-pattern-base.css", "background-color"): "stacked layout below the reveal breakpoint",
    ("vvjf-pattern-base.css", "backface-visibility"): "the flip breaks if a theme resets it",
    ("vvjr-pattern-base.css", "display"): "an off state must stay off under any theme",
    ("vvjp-horizon.css", "clip-path"): "the reduced-effects state removes the moving mask",
    ("vvjp-showroom.css", "mask-image"): "the reduced-effects state removes the moving mask",
    ("vvjp-showroom.css", "-webkit-mask-image"): "the reduced-effects state removes the moving mask",
}
# (file name, property): why a scaling size has no ceiling of its own.
R18_OK = {
    ("vvjh-panels.css", "width"): "the open panel's exact share of its own row; the row is the ceiling",
}
DECL = re.compile(r"(?:(?<=[{;])|^)\s*(--[\w-]+|[a-z-]+)\s*:\s*([^;{}]+?)(?=\s*[;}])", re.M)


def read(path):
    with open(path, encoding="utf-8") as fh:
        return fh.read()


def mask(text):
    """Blank comments, keeping every character position and newline."""
    return re.sub(r"/\*.*?\*/", lambda m: re.sub(r"[^\n]", " ", m.group(0)), text, flags=re.S)


def line_of(text, pos):
    return text.count("\n", 0, pos) + 1


def top_words(value):
    """The words of a value at parenthesis depth 0; a function stays one word."""
    words, depth, cur = [], 0, ""
    for ch in value:
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
        if depth == 0 and ch in " ,/\n\t":
            if cur:
                words.append(cur)
            cur = ""
        else:
            cur += ch
    if cur:
        words.append(cur)
    return words


def reduced_motion_spans(text):
    spans = []
    for m in re.finditer(r"@media[^{]*prefers-reduced-motion[^{]*\{", text):
        depth, i = 1, m.end()
        while i < len(text) and depth:
            depth += {"{": 1, "}": -1}.get(text[i], 0)
            i += 1
        spans.append((m.start(), i))
    return spans


def literal_for(prop, word):
    """True when `word` is a hard-coded value rule 16 wants read from a variable."""
    m = re.fullmatch(r"-?(\d*\.?\d+)(px|rem|s|ms)", word)
    if prop in MOTION:
        if word in EASES or word.startswith(("cubic-bezier(", "linear(")):
            return True
        if not m or m.group(2) not in ("s", "ms"):
            return False
        ms = float(m.group(1)) * (1000 if m.group(2) == "s" else 1)
        return 100 <= ms < 2000
    if not m or m.group(2) not in ("px", "rem"):
        return False
    px = float(m.group(1)) * (16 if m.group(2) == "rem" else 1)
    return px > 1.5


def yml_value(text, key):
    m = re.search(r"^%s:[ \t]*(.*)$" % key, text, re.M)
    return m.group(1).strip().strip("'\"") if m else None


def lane_custom_props(lane):
    """Every custom property VVJ declares or sets, and those templates set from Views options."""
    own, editor = set(), set()
    paths = []
    for mod in MODULES + ["vvj_core"]:
        paths += glob.glob(os.path.join(lane, mod, "**", "*.css"), recursive=True)
        paths += glob.glob(os.path.join(lane, mod, "**", "*.js"), recursive=True)
        paths += glob.glob(os.path.join(lane, mod, "**", "*.twig"), recursive=True)
    for path in paths:
        if f"{os.sep}tests{os.sep}" in path or "node_modules" in path:
            continue
        text = read(path)
        if path.endswith(".css"):
            own |= set(m.group(1) for m in re.finditer(r"(--[\w-]+)\s*:", mask(text)))
        elif path.endswith(".js"):
            own |= set(re.findall(r"""['"`](--[\w-]+)['"`]""", text))
            own |= set(re.findall(r"(--[\w-]+)\s*:", text))
        else:
            for attr in re.findall(r"""style=(?:"[^"]*"|'[^']*')""", text, re.S):
                names = set(re.findall(r"(--[\w-]+)\s*:", attr))
                own |= names
                editor |= names
            # Templates also build the attribute from a list: merge(['--box-width: ' ~ x]).
            merged = set(re.findall(r"""['"](--[\w-]+)\s*:""", text))
            own |= merged
            editor |= merged
    return own, editor


def pattern_css(lane):
    files = []
    for mod in MODULES:
        for path in glob.glob(os.path.join(lane, mod, "patterns", "**", "*.css"), recursive=True):
            if f"{os.sep}bridge{os.sep}" not in path:
                files.append(path)
    base = os.path.join(lane, SHARED_BASE)
    if os.path.exists(base):
        files.append(base)
    return sorted(files)


def check_css(path, lane, own, editor, out):
    raw = read(path)
    text = mask(raw)
    rel = os.path.relpath(path, lane)
    name = os.path.basename(path)
    shared = rel == SHARED_BASE
    calm = reduced_motion_spans(text)
    for m in DECL.finditer(text):
        prop, value = m.group(1), m.group(2)
        at = f"{rel}:{line_of(text, m.start(1))}"
        if prop.startswith("--"):
            if not shared and COLOUR.search(re.sub(r"url\([^)]*\)", "", value)):
                out.append(f"R4 {at} colour literal in {prop}")
            if prop in editor and not name.endswith("-pattern-base.css") and not shared:
                out.append(f"R17 {at} redeclares {prop}, set from a Views option")
            continue
        bare = re.sub(r"url\([^)]*\)", "", value)
        fallback_free = re.sub(r"var\(--vvj[\w-]*,[^()]*\)", "", bare)
        if COLOUR.search(fallback_free):
            out.append(f"R4 {at} colour literal in {prop}: {value[:60]}")
        for var in re.findall(r"var\(\s*(--[\w-]+)", bare):
            if var not in own:
                out.append(f"R5 {at} var({var}) is not a VVJ variable")
        if "!important" in value and (name, prop) not in IMPORTANT_OK:
            if not any(a <= m.start() < b for a, b in calm):
                out.append(f"R17 {at} !important on {prop} has no stated reason")
        if prop in SPACE or prop == "border-radius" or prop == "font-size" or prop in MOTION:
            for word in top_words(bare.replace("!important", "")):
                if literal_for(prop, word):
                    out.append(f"R16 {at} {prop} hard-codes {word}")
        elif prop in ("font", "font-family") and FONT_LITERAL.search(re.sub(r"var\([^()]*\)", "", bare)):
            out.append(f"R16 {at} {prop} names a font; use a --vvj-font-* variable")
        elif prop == "box-shadow" and "var(" not in bare and bare.strip() not in ("none", "inherit"):
            out.append(f"R16 {at} box-shadow hard-codes its value")
        if prop in R18_PROPS and SCALING.search(bare) and (name, prop) not in R18_OK:
            if not re.search(r"\b(?:clamp|min|max)\(", bare):
                out.append(f"R18 {at} {prop} scales with no floor or ceiling: {value[:60]}")
    for m in THEME_CLASS.finditer(text):
        out.append(f"R5 {rel}:{line_of(text, m.start())} theme class .{m.group(1)}")


def check_js(path, lane, out):
    text = read(path)
    rel = os.path.relpath(path, lane)
    for m in re.finditer(r"""['"`][^'"`\n]*?""" + THEME_CLASS.pattern, text):
        out.append(f"R5 {rel}:{line_of(text, m.start())} theme class .{m.group(1)}")


def check_yml(path, lane, out):
    text = read(path)
    rel = os.path.relpath(path, lane)
    if yml_value(text, "stage") not in ("open", "boxed"):
        out.append(f"R9 {rel} needs stage: open or boxed")
    if yml_value(text, "ground") not in ("dark", "light"):
        out.append(f"R9 {rel} needs ground: dark or light")
    if yml_value(text, "placement") not in ("outer-margin", "inner-padding", "both", "neither"):
        out.append(f"R15 {rel} needs placement: outer-margin, inner-padding, both or neither")
    serves = yml_value(text, "serves")
    tags = [t.strip() for t in re.sub(r"[\[\]]", "", serves or "").split(",") if t.strip()]
    if not 2 <= len(tags) <= 4 or set(tags) - KINDS:
        out.append(f"R12 {rel} needs serves: 2 to 4 known kinds of site, got {serves!r}")


def run(lane):
    out = []
    own, editor = lane_custom_props(lane)
    css = pattern_css(lane)
    yml, js = [], []
    for mod in MODULES:
        yml += glob.glob(os.path.join(lane, mod, "patterns", "*", "pattern.yml"))
        js += glob.glob(os.path.join(lane, mod, "patterns", "**", "*.js"), recursive=True)
    for path in css:
        check_css(path, lane, own, editor, out)
    for path in js:
        check_js(path, lane, out)
    for path in sorted(yml):
        check_yml(path, lane, out)
    return out, len(css), len(yml)


def self_test():
    good_css = """.vvj-pattern--p { padding: var(--vvj-space-8, 8px); gap: clamp(8px, 2cqi, 24px); border-radius: 50%; color: var(--vvj-ink);
  font-size: var(--vvj-text-14, 0.875rem); transition: opacity var(--vvj-dur-200, 0.2s) var(--vvj-ease-plain, ease); width: min(100cqi, 800px); }
@media (prefers-reduced-motion: reduce) { .vvj-pattern--p { animation: none !important; } }
"""
    good_yml = "label: P\nstage: open\nground: dark\nplacement: neither\nserves: [events, saas]\n"
    base = ".vvj-pattern { --vvj-ink: #111; --vvj-space-8: 8px; --vvj-text-14: 0.875rem; --vvj-dur-200: 0.2s; --vvj-ease-plain: ease; }\n"
    template = '<div style="--editor-gap: 3px"></div>\n'
    cases = [
        ("clean", None, None, None),
        ("R4 hex", ".vvj-pattern--p { color: #123456; }\n", None, "R4 "),
        ("R5 foreign var", ".vvj-pattern--p { color: var(--r-text); }\n", None, "R5 "),
        ("R5 theme class", ".solo-card .x { color: var(--vvj-ink); }\n", None, "R5 "),
        ("R9 no stage", None, "label: P\nground: dark\nplacement: neither\nserves: [events, saas]\n", "R9 "),
        ("R12 unknown kind", None, "stage: open\nground: dark\nplacement: neither\nserves: [bakery, saas]\n", "R12 "),
        ("R15 no placement", None, "stage: open\nground: dark\nserves: [events, saas]\n", "R15 "),
        ("R16 spacing", ".vvj-pattern--p { padding: 12px; }\n", None, "R16 "),
        ("R16 ease", ".vvj-pattern--p { transition: opacity var(--vvj-dur-200, 0.2s) ease-out; }\n", None, "R16 "),
        ("R16 font", ".vvj-pattern--p { font: 600 var(--vvj-text-14, 0.875rem)/1 menlo, monospace; }\n", None, "R16 "),
        ("R17 important", ".vvj-pattern--p { color: var(--vvj-ink) !important; }\n", None, "R17 "),
        ("R17 editor var", ".vvj-pattern--p { --editor-gap: 9px; }\n", None, "R17 "),
        ("R18 unbounded", ".vvj-pattern--p { width: 50cqi; }\n", None, "R18 "),
    ]
    failed = 0
    for name, css, yml, want in cases:
        with tempfile.TemporaryDirectory() as lane:
            pdir = os.path.join(lane, "vvja", "patterns", "p")
            os.makedirs(pdir)
            os.makedirs(os.path.join(lane, "vvja", "templates"))
            os.makedirs(os.path.dirname(os.path.join(lane, SHARED_BASE)))
            with open(os.path.join(lane, SHARED_BASE), "w", encoding="utf-8") as fh:
                fh.write(base)
            with open(os.path.join(lane, "vvja", "templates", "t.html.twig"), "w", encoding="utf-8") as fh:
                fh.write(template)
            with open(os.path.join(pdir, "p.css"), "w", encoding="utf-8") as fh:
                fh.write(css or good_css)
            with open(os.path.join(pdir, "pattern.yml"), "w", encoding="utf-8") as fh:
                fh.write(yml or good_yml)
            found, _, _ = run(lane)
        ok = not found if want is None else any(f.startswith(want) for f in found)
        print(("PASS" if ok else "FAIL"), name, "" if ok else found)
        failed += not ok
    print(f"self-test: {len(cases) - failed}/{len(cases)}")
    return 1 if failed else 0


def main():
    default = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
    parser = argparse.ArgumentParser()
    parser.add_argument("--lane", default=default)
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        return self_test()
    found, css, yml = run(args.lane)
    for line in found:
        print(line)
    counts = {}
    for line in found:
        counts[line.split()[0]] = counts.get(line.split()[0], 0) + 1
    print(f"checked {css} CSS files, {yml} pattern.yml: {len(found)} findings {sorted(counts.items())}")
    if not css or not yml:
        print("nothing was checked")
        return 1
    return 1 if found else 0


if __name__ == "__main__":
    sys.exit(main())
