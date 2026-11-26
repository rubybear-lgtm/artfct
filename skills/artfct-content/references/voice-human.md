# Human voice (draft register)

A third register, proposed 25 Nov 2026, for blog posts that should read like an engineer
wrote them for other engineers. It is modeled on eight posts that reached the Hacker News
front page that week (hypercritical.co, fzakaria.com, michaelharley.net, iankduncan.com,
rida.dev, modaic.dev, zydra.sh, nickvsnetworking.com). The patterns come from those
posts. Their sentences do not, and none of their text should be reused.

A draft in this voice declares `register: artfct-human`. The linter then skips the
corpus's structural rules (question-shaped H2s, an FAQ on explainers, the 30-word terse
lead), lowers the word floors, and warns on launch-speak as `W16`. A limits section can be
called "What doesn't work yet" and still renders as one.

## Who is talking

One person, by name, in the first person. "I" carries the story: why it was built, what
went wrong, what is still unfinished. "We" is only for things the team did together, and
"you" only when telling the reader to do something concrete.

The byline is a person, not "The artfct team". A post nobody signed reads as marketing,
however it is written.

## Habits to imitate

1. **Open on something that happened.** A date, a commit, a message, someone else's post.
   Do not open on a market claim or a definition.
2. **Specifics over adjectives.** Numbers with units, version numbers, file paths, issue
   numbers, prices. "62 skills, Perplexity takes 32" beats "broad compatibility".
3. **Say what did not work.** Abandoned approaches, slow results, clients that are still
   unconfirmed. Put the failure in the body, not in a footnote nobody reads.
4. **Grade your confidence claim by claim.** "Tested", "should work", "haven't tried it".
   Never one blanket "supported".
5. **Credit people by name, early, and once.** Link their work. Disagree with them by name
   if you disagree.
6. **Short beats after long sentences.** A one-line paragraph when something lands. Two
   or three per post, not twenty.
7. **Asides in parentheses.** They keep the main sentence moving and carry most of the
   personality.
8. **Plain labels for headings.** "Install", "What's in the bundle", "What doesn't work
   yet". Not "How does X help teams unlock Y?" Short posts can skip headings.
9. **State opinions flatly.** "This is the part pstack doesn't do, and it shouldn't."
   No "we believe".
10. **Price and links stated once, then stop.** No urgency, no "start today".
11. **End on a callback** to the opening or the title, or on an invitation. Never a recap.

## Things this voice never does

- Launch-speak: "excited to announce", "game-changing", "seamless", "unlock", "leverage",
  "empower", "supercharge".
- "In this post, we'll explore", "In conclusion", "Key takeaways".
- Abstract plural subjects as the main actor ("Teams", "Organizations").
- Repeating a disclaimer. Say "not official pstack" once, where it matters.
- A FAQ that restates the body. If a question needs answering, answer it in the body.
- Invented anecdotes. Every "I did X" has to be true; when a draft needs a story the
  author hasn't told yet, mark it for them instead of making one up.

## Mechanics

- Contractions are normal.
- Sentence length varies on purpose: some 30-word sentences, some three-word ones.
- Code blocks only for commands the reader will paste.
- Profanity: not in artfct posts. The HN samples use it; this brand doesn't need it.
