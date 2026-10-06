# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the
codebase.

This repo already had a structured `docs/` folder before these skills arrived, and it is the
authority. The layout below is **not** the stock single-context layout — read this file rather
than assuming it.

## Layout

Single context, with the record of "why" kept in `docs/`:

```
/
├── CONTEXT.md          ← glossary only: canonical terms + terms to avoid
├── CLAUDE.md           ← the map: which docs/*.md file covers which area
└── docs/
    ├── overview.md     ← conventions, coding standards, known rough edges
    ├── cards.md        ← one file per feature area; each carries its own rationale
    └── …
```

**There is no `docs/adr/` in this repo, and one should not be created.** Decisions, the
alternatives rejected and the failures that forced them are recorded in the relevant
`docs/*.md` file, inline with the feature they constrain — see `docs/ai-integration.md`'s
prompt-design rules for what that looks like in practice. `CLAUDE.md` makes those files the
project's only record of why, so a second home for rationale would split it. Record a decision
where the feature is documented.

## Before exploring, read these

- **`CONTEXT.md`** at the repo root: the glossary. Read it first, always — it is short.
- **The relevant `docs/*.md`**, chosen from the table in `CLAUDE.md`. Read only the file(s)
  covering the area you are about to touch; don't load the whole set for a small change. These
  files carry both the architecture and the intent, so the rationale for what you are changing is
  in there.

If a file doesn't exist, **proceed silently**. Don't flag its absence and don't propose creating
it upfront.

## Use the glossary's vocabulary

When your output names a domain concept (an issue title, a refactor proposal, a hypothesis, a test
name, a variable), use the term as `CONTEXT.md` defines it, and never a term it lists under
`_Avoid_`. Several of these distinctions are load-bearing rather than stylistic — Term vs. base
word, Words vs. Refresher, wordbox vs. theme — and collapsing them produces wrong work, not just
inconsistent wording.

If the concept you need isn't in the glossary yet, that's a signal: either you're inventing
language the project doesn't use (reconsider) or there's a real gap (note it for
`/domain-modeling`).

## Writing it down

- A **term** resolved during a session goes in `CONTEXT.md`, in the format already used there:
  one or two sentences, plus `_Avoid_` for the words it displaces. Keep it free of implementation
  detail — it is a glossary and nothing else.
- A **decision** goes in the `docs/*.md` file for the area it affects, in that file's own voice,
  together with the alternative it rules out and what made the choice necessary. Offer this only
  when the decision is hard to reverse, surprising without context, and the result of a real
  trade-off; otherwise skip it.
- Touching something documented means updating its doc in the same change — that is already a
  standing rule in `CLAUDE.md`, not an extra step these skills add.

## Flag conflicts

If your output contradicts something a `docs/*.md` file states as intentional, surface it rather
than silently overriding:

> _`docs/cards.md` says a phrase card's brackets hold the focus word, not the phrase — this change
> reverses that. Worth reopening because…_
