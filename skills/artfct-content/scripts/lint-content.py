#!/usr/bin/env python3
"""Validate an artfct-content draft against the corpus-derived rules.

Deterministic and dependency-free. Two callers: the artfct-content Pi
extension (which blocks a publish on any error) and a human running it
directly.

    lint-content.py DRAFT [--surface S] [--register R] [--json]

Exit code 1 when there is at least one error. Warnings never fail the run.
The rules encode references/feature-inventory.md (what may be claimed),
references/page-anatomy.md (block order) and references/surfaces.md
(per-surface shape).
"""

from __future__ import annotations

import argparse
import html
import json
import re
import sys
from html.parser import HTMLParser
from pathlib import Path

SURFACES = ("feature-showcase", "comparison", "explainer", "programmatic")

SURFACE_WORDS = {
    "feature-showcase": (900, 1400),
    "comparison": (1200, 2500),
    "explainer": (2000, 3000),
    "programmatic": (150, 600),
}

SURFACE_H2 = {
    "feature-showcase": (5, 7),
    "comparison": (5, 9),
    "explainer": (6, 9),
    "programmatic": (2, 4),
}

# The two strings a search result is built from. Both bands are inclusive, and
# seo-audit.py imports them rather than repeating the numbers, so the scorer and
# the publish gate cannot disagree about what fits.
TITLE_RANGE = (45, 60)
META_RANGE = (120, 160)

# Terms that describe something artfct is not, by decision (feature-inventory
# section 3). Blocked when asserted of artfct; warned otherwise, because a
# comparison page may legitimately name a competitor's category.
NON_GOAL_TERMS = [
    "artifact store", "static site hosting", "self-hosted", "self hosted",
    "self-hosting", "self hosting", "on-prem", "on prem", "byoc",
    "memory layer", "notebook", "notebooks", "wiki", "cdn", "pdf", "video",
    "military-grade", "bank-level", "first and only", "soc 2", "soc2",
    "iso 27001", "hipaa", "pci dss", "gdpr compliant", "fedramp",
]

# A sentence that denies the term is correct content, not a defect:
# "Artfct is not a wiki" is exactly the boundary the corpus holds.
NEGATION = re.compile(
    r"\b(not|never|no|none|isn't|aren't|doesn't|don't|didn't|cannot|can't|without)\b"
)

# Connection methods retired in RUB-432. No context makes these correct.
RETIRED_TERMS = [
    "stdio", "local server", "local mcp server", "npx artfct",
    "npm install -g artfct", "artfct deploy", "artfct login", "artfct cli",
    "command-line tool", "cli", "deploy_to_canvas",
]

# Absolute claims artfct cannot support.
ABSOLUTE_PATTERNS = [
    (r"\bguarantee(?:s|d)?\b", "guarantee"),
    (r"\bnever (?:loses?|fails?|goes? down)\b", "never loses or fails"),
    (r"\balways available\b", "always available"),
    (r"\b100%\s*(?:uptime|reliable|accurate|secure)\b", "100% claim"),
    (r"\bunlimited\b", "unlimited"),
    (r"\bthe only\b", "the only"),
    (r"\bzero downtime\b", "zero downtime"),
]

LABS = ["claude", "chatgpt", "openai", "anthropic", "copilot", "cursor", "gemini", "windsurf"]

DISPARAGEMENT = [
    "no controls", "no governance", "no audit", "no rbac", "no permissions",
    "no admin controls", "nothing to govern", "without any controls",
    "no security controls", "no retention",
]

FILLER = [
    "seamless", "seamlessly", "revolutionary", "game-changing", "game changing",
    "cutting-edge", "cutting edge", "unleash", "supercharge", "empower",
    "leverage", "delve", "robust solution", "best-in-class", "world-class",
    "in today's fast-paced", "state-of-the-art", "synergy", "paradigm shift",
]

TERSE_MARKERS = [
    r"that's it\b", r"that's the whole\b", r"\bdone\.", r"\bno setup\b",
]

# The register rules that are mechanical. glean-formal carries no contractions
# and no second-person imperative; artfct-terse does both. Without these the
# voice spec was prose nobody checked, and a draft drifted through.
CONTRACTIONS = re.compile(
    r"\b(don't|doesn't|isn't|aren't|can't|won't|couldn't|shouldn't|wouldn't|"
    r"hasn't|haven't|hadn't|didn't|wasn't|weren't|it's|that's|there's|here's|"
    r"what's|you're|we're|they're|i'm|let's|we've|you've|they've|we'll|you'll|"
    r"they'll|i'll|i've|i'd|we'd|you'd|they'd)\b"
)
IMPERATIVE = re.compile(r"\byou (should|can|could|need to|will want|might want|may want|have to)\b")

UNVERIFIED_TOOLS = ["chatgpt", "claude app", "cursor", "vs code", "copilot chat", "windsurf", "zed"]

PLACEHOLDER = re.compile(r"\{\{[A-Z_]+\}\}")

QUESTION_STARTS = (
    "what", "why", "how", "when", "where", "who", "which", "can", "does",
    "do ", "is ", "are ", "should", "will",
)


class Doc:
    """Prose, structure and link text of one draft, HTML or Markdown."""

    def __init__(self) -> None:
        self.headings = []
        self.paragraphs = []
        self.lead = ""
        self.faq_count = 0
        self.has_limits = False
        self.has_updated = False
        self.body_links = 0
        self.surface = ""
        self.register = ""
        self.is_html = False
        self.raw = ""


class _Extract(HTMLParser):
    SKIP_TAGS = {"script", "style", "pre", "code", "nav", "footer", "head", "title"}
    SKIP_CLASSES = {"toc", "byline", "mark", "meta", "kicker", "brand"}
    # Inline elements must not break a run of text. Resetting the buffer on
    # every start tag silently discarded everything before a nested <a>, which
    # is most of the sentence.
    INLINE = {
        "a", "b", "strong", "em", "i", "code", "span", "sup", "sub",
        "abbr", "cite", "small", "br", "mark", "time", "q",
    }

    def __init__(self, doc: Doc) -> None:
        super().__init__(convert_charrefs=True)
        self.doc = doc
        # The stack of elements currently being skipped. A depth counter is
        # not enough: any end tag would decrement it, so <nav> stopped being
        # skipped at its first nested close and the table of contents leaked
        # into the prose and the link count.
        self.skip_stack = []
        self.classes = []
        self.buf = []
        self.in_limits = False
        self.suppress_depth = 0

    def _blocked(self, tag, attrs) -> bool:
        if tag in self.SKIP_TAGS:
            return True
        classes = set((attrs.get("class") or "").split())
        return bool(classes & self.SKIP_CLASSES)

    def handle_starttag(self, tag, attrs_list) -> None:
        attrs = {k: (v or "") for k, v in attrs_list}
        if tag == "body":
            self.doc.surface = attrs.get("data-surface", "")
            self.doc.register = attrs.get("data-register", "")
        if self._blocked(tag, attrs):
            self.skip_stack.append(tag)
            return
        if self.skip_stack:
            return
        classes = set((attrs.get("class") or "").split())
        if tag == "details" and "q" in classes:
            self.doc.faq_count += 1
        if tag == "section" and "limits" in classes:
            self.in_limits = True
        # The limits, FAQ and CTA blocks are fixed furniture, not content
        # sections, so their headings must not count toward the H2 rule.
        if tag == "section" and (classes & {"limits", "faq", "cta"}):
            self.suppress_depth += 1
        if tag == "a" and attrs.get("href") and "btn" not in classes and not self.in_limits:
            self.doc.body_links += 1
        self.classes = list(classes)
        if tag not in self.INLINE:
            self.buf = []

    def handle_endtag(self, tag) -> None:
        if self.skip_stack:
            if tag == self.skip_stack[-1]:
                self.skip_stack.pop()
            return
        text = re.sub(r"\s+", " ", "".join(self.buf)).strip()
        if text:
            if tag in ("h1", "h2", "h3", "h4"):
                if self.suppress_depth == 0:
                    self.doc.headings.append((int(tag[1]), text))
            elif tag == "p":
                if "lead" in self.classes and not self.doc.lead:
                    self.doc.lead = text
                else:
                    self.doc.paragraphs.append(text)
            elif tag == "li":
                # Limits bullets and FAQ answers are prose and make claims,
                # so they are checked like any other sentence. Excluding them
                # left a hole a claim could be published through.
                self.doc.paragraphs.append(text)
            if re.search(r"last updated", text, re.I):
                self.doc.has_updated = True
        if tag == "section" and self.in_limits:
            self.in_limits = False
        if tag == "section" and self.suppress_depth:
            self.suppress_depth -= 1
        # Closing an inline element must not end the run of text either, or
        # the sentence dies at its first link.
        if tag not in self.INLINE:
            self.buf = []

    def handle_data(self, data) -> None:
        if not self.skip_stack:
            self.buf.append(data)


def load(path: Path) -> Doc:
    doc = Doc()
    raw = path.read_text(encoding="utf-8", errors="replace")
    doc.raw = raw
    if path.suffix.lower() in (".html", ".htm") or "<html" in raw[:400].lower():
        doc.is_html = True
        _Extract(doc).feed(raw)
        if not doc.has_updated and re.search(r"last updated", raw, re.I):
            doc.has_updated = True
        if not doc.has_limits and re.search(r'class="[^"]*limits', raw):
            doc.has_limits = True
    else:
        _markdown(doc, raw)
    return doc


def _markdown(doc: Doc, raw: str) -> None:
    body = re.sub(r"\x60\x60\x60.*?\x60\x60\x60", "", raw, flags=re.S)
    lines = body.split("\n")
    para = []
    fence = False
    for line in lines:
        stripped = line.strip()
        if stripped.startswith("\x60\x60\x60"):
            fence = not fence
            continue
        if fence:
            continue
        m = re.match(r"^(#{1,4})\s+(.*)$", stripped)
        if m:
            if para:
                doc.paragraphs.append(" ".join(para))
                para = []
            doc.headings.append((len(m.group(1)), m.group(2).strip()))
            continue
        if not stripped:
            if para:
                doc.paragraphs.append(" ".join(para))
                para = []
            continue
        para.append(stripped)
    if para:
        doc.paragraphs.append(" ".join(para))
    if doc.paragraphs:
        doc.lead = doc.paragraphs[0]
        doc.paragraphs = doc.paragraphs[1:]
    # Drop the fixed furniture headings so they never count as content H2s.
    doc.headings = [
        (lvl, t) for lvl, t in doc.headings
        if not re.match(
            r"^(questions?|what this (does not|is not)|limits|out of scope)\b",
            t.strip(), re.I,
        )
    ]
    doc.faq_count = len(re.findall(r"^#{2,4}\s+.*\?$", body, re.M))
    doc.has_limits = bool(re.search(
        r"^#{2,4}\s+.*(does not|what this (is not|does not)|limits|out of scope)",
        body, re.M | re.I,
    ))
    doc.has_updated = bool(re.search(r"last updated", raw, re.I))
    doc.body_links = len(re.findall(r"\[[^\]]+\]\((?!\#)[^)]+\)", body))


def sentences(text: str):
    parts = re.split(r"(?<=[.!?])\s+", re.sub(r"\s+", " ", text).strip())
    return [p for p in parts if len(p.split()) >= 3]


def lint(doc: Doc, surface: str, register: str) -> dict:
    errors = []
    warnings = []

    def err(rule, message, excerpt="") -> None:
        errors.append({"rule": rule, "message": message, "excerpt": excerpt[:200]})

    def warn(rule, message, excerpt="") -> None:
        warnings.append({"rule": rule, "message": message, "excerpt": excerpt[:200]})

    prose = " ".join([doc.lead] + doc.paragraphs)
    sents = sentences(prose)
    words = len(prose.split())

    # Claim rules are checked per sentence, and the sentences come from the
    # extracted prose rather than the raw HTML: the raw text runs the title,
    # kicker and byline together with the first paragraph, which merges
    # unrelated text into one "sentence" and makes self-reference unreliable.
    claim_sents = []
    for unit in [doc.lead] + doc.paragraphs + [t for _, t in doc.headings]:
        claim_sents.extend(sentences(unit))

    for token in sorted(set(PLACEHOLDER.findall(doc.raw))):
        err("E11", "unfilled template token " + token, token)

    for s in claim_sents:
        low = s.lower()
        self_ref = bool(re.search(r"\b(artfct|we|our|us)\b", low))
        for term in NON_GOAL_TERMS:
            if term in low:
                if NEGATION.search(low):
                    continue
                if self_ref:
                    err("E1", '"' + term + '" describes something artfct is not (feature-inventory section 3)', s)
                else:
                    warn("E1", '"' + term + '" is out of scope for artfct; confirm it is not asserted of the product', s)
        for term in RETIRED_TERMS:
            if re.search(r"\b" + re.escape(term) + r"\b", low):
                err("E2", '"' + term + '" is not an artfct connection method (retired in RUB-432)', s)
        for pattern, label in ABSOLUTE_PATTERNS:
            if re.search(pattern, low):
                err("E3", "unsupportable absolute claim: " + label, s)
        if any(lab in low for lab in LABS) and any(d in low for d in DISPARAGEMENT):
            err("E4", "claims a major lab lacks controls; false since 2026 and it loses the room", s)
        if "end-to-end encrypt" in low and not re.search(r"free|ephemeral|five-day|5-day|link", low):
            err("E5", "end-to-end encryption is true of the free ephemeral tier only; qualify or drop", s)
        if re.search(r"we (never|don't|do not) see", low):
            err("E5", 'unsupported privacy claim; the supported claim is "never used to train AI"', s)
        # Naming an unverified tool is only a defect when the sentence claims
        # it was verified. The correct shape lists both and says which is
        # which, so a disambiguating word clears it.
        if any(t in low for t in UNVERIFIED_TOOLS) and re.search(r"\bverified\b|\btested\b", low):
            if not re.search(r"documented|unverified|not verified|not tested|not yet|untested", low):
                err("E6", "claims verification for a tool whose path is only documented, not tested", s)
        for filler in FILLER:
            if filler in low:
                warn("W9", 'marketing filler: "' + filler + '"', s)
        if register == "glean-formal":
            for marker in TERSE_MARKERS:
                if re.search(marker, low):
                    warn("W10", "terse-register marker in a glean-formal page", s)
            hit = CONTRACTIONS.search(low)
            if hit:
                warn("W14", 'contraction "' + hit.group(0) + '" in a glean-formal page; the register spells it out', s)
            if IMPERATIVE.search(low):
                warn("W15", "second-person imperative in a glean-formal page", s)

    lead_words = len(doc.lead.split())
    if doc.lead:
        if register == "artfct-terse":
            if lead_words > 30:
                warn("W1", "terse lead is " + str(lead_words) + " words; keep it under 30", doc.lead)
        elif not (35 <= lead_words <= 80):
            warn("W1", "lead is " + str(lead_words) + " words; the corpus runs 40-70", doc.lead)
        if re.match(r"^in this (article|post|guide|piece)", doc.lead.strip(), re.I):
            warn("W2", "the corpus opens 0.4% of pages this way; open answer-first instead", doc.lead)
    else:
        warn("W1", "no lead paragraph found", "")

    h2s = [t for lvl, t in doc.headings if lvl == 2]
    lo, hi = SURFACE_H2.get(surface, (5, 9))
    if surface != "programmatic" and not (lo <= len(h2s) <= hi):
        warn("W3", str(len(h2s)) + " H2s; " + surface + " runs " + str(lo) + "-" + str(hi), ", ".join(h2s[:4]))
    if h2s:
        shaped = sum(
            1 for h in h2s
            if h.strip().endswith("?") or h.strip().lower().startswith(QUESTION_STARTS)
        )
        if surface != "programmatic" and shaped / len(h2s) < 0.4:
            warn("W4", "only " + str(shaped) + "/" + str(len(h2s)) + " H2s are question-shaped; the corpus mean is 5.9 per page", "")
    if surface != "programmatic" and not doc.has_updated:
        warn("W5", 'no "Last updated" line; 43-46% of the corpus carries one', "")
    if surface in ("comparison", "explainer") and doc.faq_count < 4:
        warn("W6", str(doc.faq_count) + " FAQ entries; " + surface + " pages always carry an FAQ", "")
    if surface == "feature-showcase" and not doc.has_limits:
        warn("W7", "no limits section; naming the constraint is the strongest trust signal on the page", "")
    if surface == "programmatic" and doc.faq_count:
        warn("W7", "programmatic pages carry no FAQ", "")

    mean = 0.0
    if sents:
        mean = sum(len(s.split()) for s in sents) / len(sents)
        if mean > 25:
            warn("W8", "mean sentence length " + str(round(mean, 1)) + " words; the corpus runs 19.8", "")
    if surface in SURFACE_WORDS and words:
        lo, hi = SURFACE_WORDS[surface]
        if not (lo <= words <= hi):
            warn("W13", str(words) + " words; " + surface + " runs " + str(lo) + "-" + str(hi), "")
    if doc.body_links < 3 and surface != "programmatic":
        warn("W11", str(doc.body_links) + " in-body links; the corpus median is 7", "")

    # The title and the meta description are the two strings a search result is
    # built from, and nothing else in the pipeline measures them. Sixteen of the
    # eighteen drafts carried a description past the 160 characters a result
    # shows, because no check ever said so and a warning would not have stopped
    # them being published.
    if doc.is_html:
        title = re.search(r"<title>(.*?)</title>", doc.raw, re.S)
        # A search result shows the decoded text, and the renderer escapes an
        # apostrophe as `&#039;` to keep the attribute well-formed. Measuring the
        # raw attribute counted five characters per apostrophe and failed
        # descriptions that are inside the band.
        if not title:
            err("E12", "no <title>; the tab and the search result both need one", "")
        elif not (45 <= len(html.unescape(title.group(1)).strip()) <= 60):
            err("E12", "title is " + str(len(html.unescape(title.group(1)).strip()))
                + " characters; search shows 45-60", title.group(1).strip())

        meta = re.search(r'<meta\s+name="description"\s+content="(.*?)"', doc.raw, re.S)
        if not meta:
            err("E13", "no meta description; the search result needs one", "")
        elif not (120 <= len(html.unescape(meta.group(1)).strip()) <= 160):
            err("E13", "meta description is " + str(len(html.unescape(meta.group(1)).strip()))
                + " characters; search truncates past 160", meta.group(1).strip()[:70])
    if register == "glean-formal" and re.search(r"!\s*(?:</p>|\n|$)", doc.raw):
        warn("W12", "exclamation mark in a glean-formal page", "")

    return {
        "surface": surface,
        "register": register,
        "errors": errors,
        "warnings": warnings,
        "stats": {
            "words": words,
            "lead_words": lead_words,
            "h2": len(h2s),
            "faq": doc.faq_count,
            "links": doc.body_links,
            "sentences": len(sents),
            "mean_sentence": round(mean, 1),
            "read_minutes": max(1, round(words / 230)),
            "has_limits": doc.has_limits,
            "has_updated": doc.has_updated,
        },
    }


def main() -> int:
    ap = argparse.ArgumentParser(description="Lint an artfct-content draft.")
    ap.add_argument("draft")
    ap.add_argument("--surface", choices=SURFACES)
    ap.add_argument("--register", choices=("glean-formal", "artfct-terse"))
    ap.add_argument("--json", action="store_true", help="machine-readable output")
    args = ap.parse_args()

    path = Path(args.draft)
    if not path.exists():
        print("lint-content: no such file: " + str(path), file=sys.stderr)
        return 2

    doc = load(path)
    surface = args.surface or doc.surface or "feature-showcase"
    register = args.register or doc.register or "glean-formal"
    if surface not in SURFACES:
        surface = "feature-showcase"

    result = lint(doc, surface, register)

    if args.json:
        print(json.dumps(result, indent=2))
    else:
        s = result["stats"]
        print(str(path) + "  [" + surface + " / " + register + "]")
        print("  " + str(s["words"]) + " words - " + str(s["h2"]) + " H2 - "
              + str(s["faq"]) + " FAQ - " + str(s["links"]) + " links - mean "
              + str(s["mean_sentence"]) + "w - " + str(s["read_minutes"]) + " min")
        for e in result["errors"]:
            print("  ERROR   " + e["rule"] + "  " + e["message"])
            if e["excerpt"]:
                print("          > " + e["excerpt"])
        for w in result["warnings"]:
            print("  warning " + w["rule"] + "  " + w["message"])
            if w["excerpt"]:
                print("          > " + w["excerpt"])
        if not result["errors"] and not result["warnings"]:
            print("  clean")
        print("  " + str(len(result["errors"])) + " error(s), " + str(len(result["warnings"])) + " warning(s)")

    return 1 if result["errors"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
