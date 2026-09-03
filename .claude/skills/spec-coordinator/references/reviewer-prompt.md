# Zero-context reviewer prompt template

A fresh agent per unit. It must never have seen the implementation reasoning.

## What the reviewer receives

Exactly three things:

1. The spec's **DoD checklist**, verbatim
2. The spec's **Tests** section, verbatim
3. The **diff** — `git diff <base>..HEAD -- <unit files>`

## What the reviewer must NOT receive

- The conversation, or any part of it
- The implementer's report, notes, or rationale
- The spec's Scope, Design, Rollback or Deferred sections
- Any explanation of why a choice was made

This is the point of the exercise. A reviewer who is told *why* something was
done will accept it. A reviewer holding only the contract and the diff will not.
If you find yourself wanting to add context so the reviewer "understands", that
is precisely the context that invalidates the review — the DoD item is unclear,
and the fix is to sharpen the DoD, not to brief the reviewer.

## Template

```
You are reviewing a code change in the artfct repository. You have no prior
context and you should not seek any. Do not read the conversation history. Do
not look for design documents. Judge only what is in front of you.

## The contract — the change must satisfy every item

<DoD checklist, verbatim>

## Tests that must exist

<Tests section, verbatim>

## The diff

<git diff output>

## Your report

For EACH DoD item above, report exactly one of:

- PASS — with the specific evidence in the diff that makes it true
- FAIL — with what is missing or wrong
- CANNOT VERIFY — the item is not observable from this diff

Then, for the tests:

- Which named tests are present, and which are missing
- For each test marked (negative): does it actually exercise the failure path,
  or would it pass even if the protection were removed? Quote the assertion.

Finally, note anything in the diff that is NOT required by the contract. Extra
work is scope creep and is worth flagging even when it looks harmless.

Do not suggest improvements outside the contract. Do not comment on style —
formatters and linters have already run. Your job is: does this diff satisfy
this contract, item by item.
```

## Interpreting the result

- **FAIL** → back to an implementer with the finding. Re-review with a **new** agent; the previous reviewer now has context and is compromised as a reviewer.
- **CANNOT VERIFY** → the DoD item was not observable. Either the implementation needs to make it observable, or the item was badly written. If the spec is at fault, fix the spec and say so — an unobservable DoD item will keep failing every future review.
- **Scope creep flagged** → decide deliberately. Extra work is not automatically wrong, but it was not reviewed against anything.
- **Two failed rounds on one unit** → stop, report to the user. Do not iterate silently.
