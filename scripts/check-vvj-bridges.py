#!/usr/bin/env python3
"""Static drift check for the VVJ theme bridges (no site, no browser).

Reads a lane directory holding vvj_core and the VVJ format modules (vvja ... vvjt).
  (a) no bridge library declares `dependencies` (a loop here was a 2 GB memory fatal);
  (b) every --vvjX-* token a module bridge sets is declared in that module's
      patterns/*-pattern-base.css;
  (c) every shared --vvj-theme-* role is declared in BOTH shared bridges
      (vvj_core/modules/vvj_patterns/css/bridge/).
Exit 1 with path:line on a failure, and when nothing was checked.
Usage: check-vvj-bridges.py [--lane DIR] | --self-test
"""
import argparse, glob, os, re, shutil, sys, tempfile

SHARED = "vvj_core/modules/vvj_patterns/css/bridge"
SHARED_LIBS = "vvj_core/modules/vvj_patterns/vvj_patterns.libraries.yml"


def read(path):
    with open(path, encoding="utf-8") as fh:
        return fh.read()


def strip_comments(text):
    # Keep newlines so line numbers stay true.
    return re.sub(r"/\*.*?\*/", lambda m: "\n" * m.group(0).count("\n"), text, flags=re.S)


def decls(path, name_re):
    """Return {token: first line} for custom properties DECLARED (name followed by a colon)."""
    out = {}
    for n, line in enumerate(strip_comments(read(path)).split("\n"), 1):
        for m in re.finditer(r"(?<![\w-])(" + name_re + r")\s*:", line):
            out.setdefault(m.group(1), n)
    return out


def libraries_files(lane):
    files = sorted(glob.glob(os.path.join(lane, "vvj[a-z]", "vvj[a-z].libraries.yml")))
    shared = os.path.join(lane, SHARED_LIBS)
    return files + ([shared] if os.path.isfile(shared) else [])


def check_a(lane, errs, cov):
    for path in libraries_files(lane):
        lib, block = None, []
        blocks = []
        for n, raw in enumerate(read(path).split("\n"), 1):
            line = raw.split("#", 1)[0].rstrip()
            if re.match(r"^[^\s].*:\s*$", line):
                lib = line[:-1].strip()
                blocks.append((lib, []))
            elif blocks and line.strip():
                blocks[-1][1].append((n, line))
        for lib, lines in blocks:
            body = "\n".join(l for _, l in lines)
            if "bridge" not in lib and "/bridge/" not in body:
                continue
            cov["libs"] += 1
            for n, l in lines:
                if re.match(r"^\s+dependencies\s*:", l):
                    errs.append("%s:%d: bridge library '%s' declares dependencies" % (path, n, lib))


def check_b(lane, errs, cov):
    for mod in sorted(glob.glob(os.path.join(lane, "vvj[a-z]"))):
        name = os.path.basename(mod)
        bases = glob.glob(os.path.join(mod, "patterns", "*-pattern-base.css"))
        bridges = sorted(glob.glob(os.path.join(mod, "patterns", "bridge", "*.css")))
        if not bridges:
            continue
        declared = {}
        for b in bases:
            declared.update(decls(b, r"--" + name + r"-[\w-]+"))
        for br in bridges:
            cov["bridges"] += 1
            for tok, n in decls(br, r"--" + name + r"-[\w-]+").items():
                cov["tokens"] += 1
                if tok not in declared:
                    errs.append("%s:%d: %s is not declared in %s/patterns/*-pattern-base.css" % (br, n, tok, name))
        if not bases:
            errs.append("%s: no patterns/*-pattern-base.css to declare the bridge tokens" % mod)


def check_c(lane, errs, cov):
    d = os.path.join(lane, SHARED)
    solo = os.path.join(d, "vvj-patterns-bridge-solo.css")
    apex = os.path.join(d, "vvj-patterns-bridge-apex.css")
    if not (os.path.isfile(solo) and os.path.isfile(apex)):
        errs.append("%s: shared bridge file(s) missing" % d)
        return
    role = r"--vvj-theme-[\w-]+"
    a, b = decls(solo, role), decls(apex, role)
    for tok in sorted(set(a) | set(b)):
        cov["roles"] += 1
        if tok not in a:
            errs.append("%s: role %s is declared in the APEX bridge (line %d) but not here" % (solo, tok, b[tok]))
        if tok not in b:
            errs.append("%s: role %s is declared in the Solo bridge (line %d) but not here" % (apex, tok, a[tok]))


def run(lane):
    errs, cov = [], {"libs": 0, "bridges": 0, "tokens": 0, "roles": 0}
    check_a(lane, errs, cov)
    check_b(lane, errs, cov)
    check_c(lane, errs, cov)
    print("check-vvj-bridges: %d bridge libraries, %d module bridges, %d tokens, %d shared roles checked"
          % (cov["libs"], cov["bridges"], cov["tokens"], cov["roles"]))
    if not all(cov.values()):
        errs.append("a check covered nothing (counts above): not a pass")
    for e in errs:
        print("FAIL " + e)
    print("check-vvj-bridges: %s" % ("FAILED (%d)" % len(errs) if errs else "OK"))
    return 1 if errs else 0


def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(text)


def build_clean(lane):
    write(lane + "/vvja/vvja.libraries.yml",
          "vvja:\n  css:\n    theme:\n      css/vvja.css: {}\n  dependencies:\n    - core/drupal\n\n"
          "pattern-bridge-solo:\n  css:\n    theme:\n      patterns/bridge/vvja-bridge-solo.css: { weight: 206 }\n")
    write(lane + "/vvja/patterns/vvja-pattern-base.css", ":where(.vvja) {\n  --vvja-btn-bg: #000;\n}\n")
    write(lane + "/vvja/patterns/bridge/vvja-bridge-solo.css",
          ":where(.vvja) {\n  --vvja-btn-bg: var(--vvj-theme-button-bg, #000);\n}\n")
    for t in ("solo", "apex"):
        write(lane + "/" + SHARED + "/vvj-patterns-bridge-%s.css" % t,
              ":root {\n  --vvj-theme-bg: #fff;\n  --vvj-theme-text: #111;\n}\n")
    write(lane + "/" + SHARED_LIBS,
          "base:\n  css:\n    theme:\n      css/b.css: {}\n  dependencies:\n    - vvj_core/tokens\n\n"
          "theme-bridge-solo:\n  css:\n    theme:\n      css/bridge/vvj-patterns-bridge-solo.css: { weight: 100 }\n")


def self_test():
    root = tempfile.mkdtemp(prefix="vvj-bridges-self-test.")
    cases = [
        ("clean", lambda L: None, 0),
        ("a: bridge library with dependencies",
         lambda L: write(L + "/vvja/vvja.libraries.yml", read(L + "/vvja/vvja.libraries.yml")
                         + "  dependencies:\n    - vvja/vvja\n"), 1),
        ("b: bridge sets a token the base never declares",
         lambda L: write(L + "/vvja/patterns/bridge/vvja-bridge-solo.css",
                         ":where(.vvja) {\n  --vvja-ghost: #f00;\n}\n"), 1),
        ("c: role missing from the APEX bridge",
         lambda L: write(L + "/" + SHARED + "/vvj-patterns-bridge-solo.css",
                         ":root {\n  --vvj-theme-bg: #fff;\n  --vvj-theme-text: #111;\n  --vvj-theme-extra: #222;\n}\n"), 1),
    ]
    bad = 0
    try:
        for label, plant, want in cases:
            lane = os.path.join(root, str(abs(hash(label))))
            build_clean(lane)
            plant(lane)
            print("--- self-test case: " + label)
            got = run(lane)
            ok = got == want
            print("=> exit %d, expected %d: %s" % (got, want, "ok" if ok else "WRONG"))
            bad += 0 if ok else 1
    finally:
        shutil.rmtree(root, ignore_errors=True)
    print("self-test: %s" % ("PASS" if not bad else "FAIL (%d wrong)" % bad))
    return 1 if bad else 0


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--lane", default=os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..")))
    ap.add_argument("--self-test", action="store_true")
    args = ap.parse_args()
    return self_test() if args.self_test else run(args.lane)


if __name__ == "__main__":
    sys.exit(main())
