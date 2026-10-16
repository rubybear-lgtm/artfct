#!/usr/bin/env python3
"""Tests for lint-content.py. Run: python3 test_lint.py

Each error case asserts the specific rule fires, so a rule that silently
stops working fails the suite rather than passing it.
"""

import json
import subprocess
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
LINT = HERE / "lint-content.py"

CLEAN = """<!doctype html>
<html lang="en"><head><title>Keeping a churn analysis findable for the team \u2014 artfct</title>
<meta name="description" content="For a team that pays for analysis once, the second copy is waste. Artfct stores what every agent produces, so the next question starts from work that exists.">
</head>
<body data-surface="feature-showcase" data-register="glean-formal">
<div class="wrap">
<header>
<p class="kicker">Workflows</p>
<h1>Keeping a churn analysis findable</h1>
<p class="meta"><b>The artfct team</b> - Last updated 2026-10-08 - 4 min</p>
<p class="lead">A churn analysis your team already paid for should not be rebuilt next month by a different AI tool. Artfct stores what your agents produce so every tool on the team can find it, with the sources it came from.</p>
</header>
<nav class="toc"><ol><li><a href="#a">What happens today</a></li></ol></nav>
<main>
<section class="s"><h2>What happens today?</h2><p>Each tool keeps its own history, so the next agent starts from nothing and produces the report again.</p></section>
<section class="s"><h2>How does sharing work?</h2><p>A person publishes once and chooses who can open it.</p></section>
<section class="s"><h2>Who can see an artifact?</h2><p>You choose between your team, only you, or anyone with the link.</p></section>
<section class="s"><h2>What about different AI tools?</h2><p>Every connected tool reads the same store, whichever tool wrote to it.</p></section>
<section class="s"><h2>Can an agent find work before rebuilding it?</h2><p>Yes. An agent searches first, then says which artifacts it used.</p></section>
<section class="limits"><h2>What this does not do</h2><ul><li>It does not edit documents for you.</li><li>It does not accept arbitrary office files.</li></ul></section>
<section class="faq"><h2>Questions</h2>
<details class="q"><summary>Does a member need to install anything?</summary><p>No.</p></details>
<details class="q"><summary>What happens when a tool cannot connect?</summary><p>Tools that can only start local programs cannot connect.</p></details>
<details class="q"><summary>How long is a link kept?</summary><p>Permanent artifacts have no expiry.</p></details>
<details class="q"><summary>Can an admin remove something?</summary><p>Yes, and the removal is recorded.</p></details>
</section></main>
<div class="byline"><div class="av">A</div><div class="who">The artfct team</div><p class="bio">Notes on how teams keep what their AI makes.</p></div>
<section class="cta"><h2>Try it</h2><p>Share a page as a link.</p><a class="btn" href="https://artfct.dev/free">Start free</a></section>
<footer>artfct</footer></div></body></html>
"""


def run(html: str, *args: str):
    with tempfile.TemporaryDirectory() as d:
        p = Path(d) / "draft.html"
        p.write_text(html, encoding="utf-8")
        proc = subprocess.run(
            [sys.executable, str(LINT), str(p), "--json", *args],
            capture_output=True, text=True,
        )
    return proc.returncode, json.loads(proc.stdout or "{}")


def rules(result, key="errors"):
    return {e["rule"] for e in result.get(key, [])}


def check(name, html, expect_rule=None, expect_exit=None, *args):
    code, result = run(html, *args)
    if expect_rule:
        assert expect_rule in rules(result), (
            name + ": expected " + expect_rule + ", got " + json.dumps(result.get("errors"), indent=2)
        )
    if expect_exit is not None:
        assert code == expect_exit, name + ": exit " + str(code) + " != " + str(expect_exit)
    print("  ok  " + name)


def variant(sentence: str) -> str:
    """The clean draft with one sentence injected into the lead."""
    return CLEAN.replace("A churn analysis your team", sentence + " A churn analysis your team")


def main() -> int:
    print("lint-content.py")

    # The linter is regex-heavy, so a backslash that arrived as a control
    # character would silently disable a rule. Assert the source is clean.
    src = LINT.read_text(encoding="utf-8")
    stray = sorted({ord(c) for c in src if ord(c) < 0x20 and c not in "\n\t"})
    assert not stray, "control characters in lint-content.py: " + str(stray)
    print("  ok  no stray control characters in the linter source")

    check("clean draft has no errors", CLEAN, expect_exit=0)
    code, res = run(CLEAN)
    assert res["stats"]["h2"] == 5, "limits/FAQ/CTA headings must not count as content H2s: " + str(res["stats"])
    assert res["stats"]["faq"] == 4, res["stats"]
    assert res["stats"]["has_limits"] is True
    assert res["stats"]["has_updated"] is True
    print("  ok  stats read the document correctly")

    code, res = run(CLEAN)
    assert "E12" not in rules(res) and "E13" not in rules(res), \
        "the clean fixture must satisfy the title and description bands: " + json.dumps(res["errors"], indent=2)
    print("  ok  the clean fixture satisfies the title and description bands")

    # Both directions matter. The band exists because a description ran long, so
    # a suite that only tested the short side would not see that regression.
    check("E12 a title under the band",
          CLEAN.replace("Keeping a churn analysis findable for the team", "A churn page"), "E12")
    check("E12 a title over the band",
          CLEAN.replace("Keeping a churn analysis findable for the team",
                        "Keeping a churn analysis findable for the team and its partners"), "E12")
    check("E13 a description under the band",
          CLEAN.replace(
              "the second copy is waste. Artfct stores what every agent produces, so the next question starts from work that exists.",
              "it is waste."), "E13")
    check("E13 a description over the band",
          CLEAN.replace("work that exists.",
                        "work that exists, which matters because a team that cannot find its own analysis pays for it twice."), "E13")

    check("E11 unfilled token", variant("{{LEAD}}"), "E11")
    check("E1 non-goal asserted of artfct",
          variant("Artfct is an artifact store."), "E1")
    check("E2 retired connection method",
          variant("Artfct works through the CLI."), "E2")
    check("E3 absolute claim",
          variant("Artfct guarantees your work is never lost."), "E3")
    check("E4 lab disparagement",
          variant("Claude Code and Copilot give you no controls at all."), "E4")
    check("E5 unqualified encryption claim",
          variant("Everything is end-to-end encrypted."), "E5")
    check("E6 unverified tool called verified",
          variant("ChatGPT is verified and tested."), "E6")

    # The correct shape names both groups and says which is which. Without the
    # guard this is an error, and the rule punishes the sentence it asks for.
    code, res = run(variant(
        "Claude Code and Codex are verified end to end; ChatGPT and Cursor are documented paths."))
    assert code == 0, json.dumps(res["errors"], indent=2)
    print("  ok  verified-versus-documented sentence passes")

    # Text either side of an inline link must survive extraction, or claims
    # hide inside the half of the sentence that was dropped.
    linked = CLEAN.replace(
        "<p>Each tool keeps its own history, so the next agent starts from nothing and produces the report again.</p>",
        '<p>Artfct is an artifact store and <a href="https://artfct.dev">here is a link</a> in the middle of it.</p>')
    check("claims before a nested link are still checked", linked, "E1")

    code, res = run(variant("Notion is a wiki, which is a different job."))
    assert code == 0, json.dumps(res["errors"], indent=2)
    assert "E1" in rules(res, "warnings"), "E1 should warn when the term is not asserted of artfct"
    print("  ok  non-goal term about another product warns instead of failing")

    # A denial is the boundary the corpus holds, not a defect. Without the
    # negation guard this is an error and the rule is unusable.
    code, res = run(variant("Artfct is not a wiki and does not try to be one."))
    assert code == 0, json.dumps(res["errors"], indent=2)
    assert "E1" not in rules(res), "a denial must not trip the non-goal rule"
    print("  ok  denial of a non-goal passes")

    check("E1 catches pdf as an accepted type",
          variant("Artfct accepts any PDF your team has."), "E1")

    code, res = run(variant("Artfct doesn't lose work that was already shared."))
    assert "W14" in rules(res, "warnings"), "a contraction must warn in glean-formal"
    print("  ok  W14 catches a contraction in glean-formal")

    code, res = run(variant("You should publish the report before it is lost."))
    assert "W15" in rules(res, "warnings"), "a second-person imperative must warn in glean-formal"
    print("  ok  W15 catches a second-person imperative")

    # The same sentence is correct in the other register, so the rule must be
    # register-scoped rather than absolute.
    code, res = run(variant("You should publish it. It doesn't cost anything."), "--register", "artfct-terse")
    assert "W14" not in rules(res, "warnings") and "W15" not in rules(res, "warnings"), "register rules must not apply to artfct-terse"
    print("  ok  the register rules do not fire on artfct-terse")

    code, res = run(CLEAN, "--surface", "explainer")
    assert "W13" in rules(res, "warnings"), "explainer should warn about a 300-word draft"
    print("  ok  surface-specific length warning fires")

    check("E2 catches deploy_to_canvas",
          variant("Use deploy_to_canvas for quick shares."), "E2")

    print("all tests passed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
