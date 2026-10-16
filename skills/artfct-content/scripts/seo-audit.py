#!/usr/bin/env python3
"""Score an artfct-content draft against the on-page search rubric.

    python3 skills/artfct-content/scripts/seo-audit.py RENDERED [...]
    python3 skills/artfct-content/scripts/seo-audit.py RENDERED-DIR [...]
    python3 skills/artfct-content/scripts/seo-audit.py --all
    python3 skills/artfct-content/scripts/seo-audit.py --all --json

A draft is Markdown, and what this scores is the document it renders to, so
render first: `php artisan blog:render <draft> --out=<file>` for one draft,
or `php artisan blog:render --all --out=<dir>` and point this at the
directory. A positional argument is a path, or a directory of rendered
documents.

The rubric is the one the PR #39 review states it applied: title length,
meta description length, word count and H2 shape for the surface, FAQ count,
in-body links, and keyword placement in the title, the meta description and
the first hundred words. Eleven checks, equally weighted, so the score is the
share of them that pass.

Keyword placement needs a target phrase. It lives on the draft as
`data-target` on <body> so the phrase travels with the page it belongs to
rather than in a map beside this script. A draft without one is scored on the
eight checks that do not need it, and the missing target is reported.

Word counts and the two length bands come from lint-content.py, so this script
and the linter cannot disagree about how long a draft is or what fits.
"""

from __future__ import annotations

import argparse
import importlib.util
import json
import re
import subprocess
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
SKILL = HERE.parent


def _load_linter():
    """Import lint-content.py, whose hyphen keeps it out of a plain import."""
    spec = importlib.util.spec_from_file_location(
        "lint_content", HERE / "lint-content.py"
    )
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)

    return module


lint_content = _load_linter()

TITLE_RANGE = lint_content.TITLE_RANGE
META_RANGE = lint_content.META_RANGE
QUESTION_RATIO = 0.4


def first_words(text: str, count: int = 100) -> str:
    return " ".join(text.split()[:count]).lower()


def links(raw: str) -> tuple[int, int]:
    """In-body links as (internal, external). Head furniture is not a link."""
    body = raw.split("<main", 1)[-1].split("</main>", 1)[0]
    hrefs = re.findall(r'<a\s[^>]*href="([^"]+)"', body)
    useful = [h for h in hrefs if not h.startswith("#")]
    external = [h for h in useful if not re.match(r"https?://artfct\.dev", h)]

    return len(useful) - len(external), len(external)


def audit(path: Path) -> dict:
    doc = lint_content.load(path)
    surface = doc.surface or "feature-showcase"
    register = doc.register or "glean-formal"
    raw = doc.raw

    title = re.search(r"<title>(.*?)</title>", raw, re.S)
    meta = re.search(r'<meta\s+name="description"\s+content="(.*?)"', raw, re.S)
    target = re.search(r'data-target="([^"]*)"', raw)
    h1 = re.search(r"<h1>(.*?)</h1>", raw, re.S)

    title = title.group(1).strip() if title else ""
    meta = meta.group(1).strip() if meta else ""
    target = target.group(1).strip() if target else ""

    prose = " ".join([doc.lead] + doc.paragraphs)
    words = len(prose.split())
    h2s = [t for lvl, t in doc.headings if lvl == 2]
    internal, external = links(raw)

    word_lo, word_hi = lint_content.SURFACE_WORDS.get(surface, (0, 10**6))
    h2_lo, h2_hi = lint_content.SURFACE_H2.get(surface, (0, 10**6))
    shaped = sum(
        1
        for h in h2s
        if h.strip().endswith("?")
        or h.strip().lower().startswith(lint_content.QUESTION_STARTS)
    )
    opening = first_words(" ".join([doc.lead] + doc.paragraphs))
    low_title, low_meta = title.lower(), meta.lower()

    checks: list[tuple[str, bool, str]] = [
        (
            "title length",
            TITLE_RANGE[0] <= len(title) <= TITLE_RANGE[1],
            str(len(title)) + " chars",
        ),
        (
            "meta length",
            META_RANGE[0] <= len(meta) <= META_RANGE[1],
            str(len(meta)) + " chars",
        ),
        ("word count", word_lo <= words <= word_hi, str(words) + " of " + str(word_lo) + "-" + str(word_hi)),
        ("H2 count", h2_lo <= len(h2s) <= h2_hi, str(len(h2s)) + " of " + str(h2_lo) + "-" + str(h2_hi)),
        (
            "H2 question shape",
            bool(h2s) and shaped / len(h2s) >= QUESTION_RATIO,
            str(shaped) + "/" + str(len(h2s)),
        ),
        ("FAQ count", doc.faq_count >= 4, str(doc.faq_count)),
        ("in-body links", internal + external >= 3, str(internal + external)),
        ("external links", external >= 1, str(external)),
    ]

    if target:
        checks += [
            ("target in title", target.lower() in low_title, target),
            ("target in meta", target.lower() in low_meta, target),
            ("target in first 100w", target.lower() in opening, target),
        ]

    passed = sum(1 for _, ok, _ in checks if ok)

    return {
        "slug": path.stem,
        "surface": surface,
        "register": register,
        "title": title,
        "title_chars": len(title),
        "meta_chars": len(meta),
        "h1": h1.group(1).strip() if h1 else "",
        "target": target,
        "words": words,
        "h2": len(h2s),
        "faq": doc.faq_count,
        "internal": internal,
        "external": external,
        "score": round(passed / len(checks) * 100),
        "passed": passed,
        "total": len(checks),
        "checks": [{"name": n, "ok": ok, "detail": d} for n, ok, d in checks],
    }


def render_every_draft() -> list[Path]:
    """`--all` scores every draft, so it renders them first.

    The audit reads HTML and the drafts are Markdown, so globbing the drafts
    directory finds nothing. Rendering through the application keeps this flag
    meaning what it says instead of quietly scoring zero pages.
    """
    out = Path(tempfile.mkdtemp(prefix="artfct-seo-"))
    result = subprocess.run(
        ["php", "artisan", "blog:render", "--all", "--out=" + str(out)],
        cwd=SKILL.parents[1],
        capture_output=True,
        text=True,
    )

    if result.returncode != 0:
        print("seo-audit: blog:render failed\n" + (result.stderr or result.stdout), file=sys.stderr)
        raise SystemExit(2)

    return sorted(out.glob("*.html"))


def draft_paths(args) -> list[Path]:
    if args.all:
        return render_every_draft()

    paths: list[Path] = []
    for argument in args.draft:
        candidate = Path(argument)
        # A directory is what `blog:render --all --out=<dir>` writes, and the
        # audit is pointed at it rather than at nineteen file arguments.
        paths += sorted(candidate.glob("*.html")) if candidate.is_dir() else [candidate]

    return paths


def main() -> int:
    ap = argparse.ArgumentParser(description="Score artfct-content drafts for on-page search.")
    ap.add_argument("draft", nargs="*", help="a rendered document, or a directory of them")
    ap.add_argument("--all", action="store_true", help="render every draft in the drafts directory and score it")
    ap.add_argument("--json", action="store_true", help="machine-readable output")
    ap.add_argument("--quiet", action="store_true", help="summary line only")
    ap.add_argument("--floor", type=int, default=0, help="exit non-zero below this score")
    args = ap.parse_args()

    paths = draft_paths(args)

    if not paths:
        print("seo-audit: no drafts given", file=sys.stderr)
        return 2

    results = [audit(p) for p in paths]

    if args.json:
        print(json.dumps(results, indent=2))
    elif not args.quiet:
        for r in sorted(results, key=lambda r: r["score"]):
            print(str(r["score"]) + "  " + r["slug"])
            print("      title " + str(r["title_chars"]) + " - meta " + str(r["meta_chars"])
                  + " - " + str(r["words"]) + "w - " + str(r["h2"]) + " H2 - "
                  + str(r["faq"]) + " FAQ - links " + str(r["internal"]) + "/" + str(r["external"]))
            for c in r["checks"]:
                if not c["ok"]:
                    print("      FAIL  " + c["name"] + "  (" + c["detail"] + ")")
            print()

    banded = [r for r in results if r["target"]]
    if not args.json:
        print(str(len(results)) + " draft(s); mean " + str(round(sum(r["score"] for r in results) / len(results)))
              + "; at or above 90: " + str(sum(1 for r in results if r["score"] >= 90))
              + "  (* = no data-target, scored on " + str(len(results[0]["checks"])) + " checks)")
        if banded:
            print("with a target: " + str(len(banded)) + " of " + str(len(results)))
    below = [r for r in results if r["score"] < args.floor]

    return 1 if below else 0


if __name__ == "__main__":
    raise SystemExit(main())
