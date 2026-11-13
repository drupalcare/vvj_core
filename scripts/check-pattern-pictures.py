#!/usr/bin/env python3
"""Checks that every VVJ pattern which shows a picture names its image style.

Rules (plan: PLAN-image-styles-in-patterns.md, 4.1 and C1):
  P1  a pattern that shows a picture has a `pictures:` key.
  P2  every `pictures` entry has a `style` and a `responsive` (true or false).
  P3  the style id is shipped by vvj_patterns (config/optional) or by the
      pattern's own module (config/optional or config/install).
  P4  a pattern that shows no picture has no `pictures` key.

Usage:
  check-pattern-pictures.py [ROOT]   ROOT holds vvj_core/ and the vvjX/ modules
                                     (default: the folder two levels above this file).
  check-pattern-pictures.py --self-test

Exit 0 = clean, 1 = findings. Python 3 standard library only.
"""
import glob
import os
import re
import sys
import tempfile

PICTURE_WORDS = re.compile(r"\bimage\b|\bimages\b|vvj_image|vvj-media|\bphoto|\bpicture", re.I)
SHARED_STYLES_DIR = "vvj_core/modules/vvj_patterns/config/optional"


def shipped_styles(dirs):
    """Image style ids shipped as image.style.<id>.yml in the given dirs."""
    ids = set()
    for d in dirs:
        for f in glob.glob(os.path.join(d, "image.style.*.yml")):
            ids.add(os.path.basename(f)[len("image.style."):-len(".yml")])
    return ids


def parse_pattern(text):
    """Return (pictures, needs). pictures is None when the key is absent,
    else {slot: {key: value}}. Reads only what this check needs."""
    pictures = None
    needs = []
    lines = text.splitlines()
    i = 0
    while i < len(lines):
        line = lines[i]
        if re.match(r"^pictures:\s*(\{\s*\})?\s*$", line):
            pictures = {}
            i += 1
            slot = None
            while i < len(lines) and (lines[i].startswith(" ") or not lines[i].strip()):
                m_slot = re.match(r"^  ([A-Za-z0-9_]+):\s*(\{(.*)\})?\s*$", lines[i])
                m_key = re.match(r"^    ([a-z_]+):\s*(.*?)\s*$", lines[i])
                if m_slot:
                    slot = m_slot.group(1)
                    pictures[slot] = {}
                    if m_slot.group(3) is not None:
                        for part in m_slot.group(3).split(","):
                            if ":" in part:
                                k, v = part.split(":", 1)
                                pictures[slot][k.strip()] = v.strip()
                elif m_key and slot is not None:
                    pictures[slot][m_key.group(1)] = m_key.group(2)
                i += 1
            continue
        if re.match(r"^needs:\s*$", line):
            i += 1
            while i < len(lines) and (lines[i].startswith(" ") or not lines[i].strip()):
                needs.append(lines[i].strip())
                i += 1
            continue
        i += 1
    return pictures, needs


def check(root):
    findings = []
    shared = shipped_styles([os.path.join(root, SHARED_STYLES_DIR)])
    files = sorted(glob.glob(os.path.join(root, "vvj*", "patterns", "*", "pattern.yml")))
    for f in files:
        rel = os.path.relpath(f, root)
        module = rel.split(os.sep)[0]
        own = shipped_styles([
            os.path.join(root, module, "config", "optional"),
            os.path.join(root, module, "config", "install"),
        ])
        known = shared | own
        with open(f, encoding="utf-8") as fh:
            pictures, needs = parse_pattern(fh.read())
        shows_picture = bool(PICTURE_WORDS.search(" ".join(needs)))
        if shows_picture and pictures is None:
            findings.append(f"P1 {rel}: shows a picture but has no pictures key")
        if not shows_picture and pictures is not None:
            findings.append(f"P4 {rel}: shows no picture but has a pictures key")
        for slot, entry in (pictures or {}).items():
            style = entry.get("style")
            responsive = entry.get("responsive")
            if not style:
                findings.append(f"P2 {rel}: slot {slot} has no style")
            if responsive not in ("true", "false"):
                findings.append(f"P2 {rel}: slot {slot} has no responsive true or false")
            if style and style not in known:
                findings.append(f"P3 {rel}: slot {slot} style {style} is not shipped by vvj_patterns or {module}")
        if pictures is not None and not pictures:
            findings.append(f"P2 {rel}: pictures key is empty")
    return findings, len(files)


def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(text)


def self_test():
    with tempfile.TemporaryDirectory() as root:
        write(os.path.join(root, SHARED_STYLES_DIR, "image.style.vvj_wide_1600.yml"), "id: vvj_wide_1600\n")
        write(os.path.join(root, "vvjx", "config", "optional", "image.style.vvjx_own.yml"), "id: vvjx_own\n")
        ok = "label: 'A'\nneeds:\n  - 'Show the image first.'\n"
        pic = "pictures:\n  vvj_image:\n    style: STYLE\n    responsive: false\n"
        cases = {
            "good_shared": (ok.replace("needs:", pic.replace("STYLE", "vvj_wide_1600") + "needs:"), None),
            "good_own": (ok.replace("needs:", pic.replace("STYLE", "vvjx_own") + "needs:"), None),
            "good_text_only": ("label: 'T'\nneeds:\n  - 'Two text fields.'\n", None),
            "no_pictures": (ok, "P1"),
            "no_style": (ok.replace("needs:", "pictures:\n  vvj_image:\n    responsive: false\nneeds:"), "P2"),
            "no_responsive": (ok.replace("needs:", "pictures:\n  vvj_image:\n    style: vvj_wide_1600\nneeds:"), "P2"),
            "unknown_style": (ok.replace("needs:", pic.replace("STYLE", "vvj_nope") + "needs:"), "P3"),
            "text_with_pictures": ("label: 'T'\n" + pic.replace("STYLE", "vvj_wide_1600") + "needs:\n  - 'Two text fields.'\n", "P4"),
        }
        for name, (text, _) in cases.items():
            write(os.path.join(root, "vvjx", "patterns", name, "pattern.yml"), text)
        findings, _count = check(root)
        failed = []
        for name, (_text, expect) in cases.items():
            hits = [x for x in findings if f"/{name}/" in x]
            if expect is None and hits:
                failed.append(f"{name}: expected clean, got {hits}")
            if expect is not None and not any(h.startswith(expect + " ") for h in hits):
                failed.append(f"{name}: expected {expect}, got {hits}")
        # A style shipped only by ANOTHER module's own config must not count.
        write(os.path.join(root, "vvjy", "config", "optional", "image.style.vvjy_other.yml"), "id: vvjy_other\n")
        write(os.path.join(root, "vvjx", "patterns", "other_module", "pattern.yml"),
              ok.replace("needs:", pic.replace("STYLE", "vvjy_other") + "needs:"))
        findings, _count = check(root)
        if not any("/other_module/" in x and x.startswith("P3") for x in findings):
            failed.append("other_module: a style of another module was accepted")
    if failed:
        print("SELF-TEST FAILED")
        for line in failed:
            print("  " + line)
        return 1
    print("SELF-TEST PASS: 8 planted cases plus the other-module case behave as expected")
    return 0


def main(argv):
    if "--self-test" in argv:
        return self_test()
    here = os.path.dirname(os.path.abspath(__file__))
    root = argv[1] if len(argv) > 1 else os.path.dirname(os.path.dirname(here))
    findings, count = check(root)
    for line in findings:
        print(line)
    print(f"checked {count} pattern.yml: {len(findings)} findings")
    return 1 if findings else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
