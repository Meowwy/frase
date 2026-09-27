# Multi-language Vocabulary

How Frase supports learning several languages at once, the native-language-vocabulary opt-in, and
the shared UI component that switches between languages/wordboxes everywhere.

## Data model

- **`languages`** — a static, seeded reference list (ISO 639-1 `code`, English `name`,
  `native_name`, emoji `flag`), populated by `LanguageSeeder` (idempotent `updateOrCreate` on
  `code`, ~42 languages). Add a language there, not ad hoc.
- **`language_user` pivot** (`$user->languages()`) — the user's **target-language set, up to 5**.
  Carries `users_level` (nullable CEFR string `A1`..`C2`, allowed values + AI-facing descriptions
  in `config/proficiency.php`) via `->withPivot('users_level')`. `User::levelForLanguage($language)`
  reads it for a given language.
- **`users.native_language_id`**, **`users.active_language_id`** — single-value FKs.
  `native_language_id` is the user's own language (used for translations/definitions, see
  [ai-integration](ai-integration.md)). `active_language_id` is the language single-language screens
  **open on** (see "Which language a screen opens on" below) — not a save destination: capture has
  none any more.
- These FKs **supersede** the legacy free-text `users.target_language` / `users.native_language`
  string columns, which are kept temporarily for backfill and are still what
  `RegisteredUserController@store` writes on signup — see [overview](overview.md) "Known rough edges". A
  brand-new user has no pivot rows and no `native_language_id` until they visit `/profile/edit`.
- `cards`, `wordboxes`, and `themes` each carry their own `language_id` — a card belongs to
  exactly **one** language. "**General vocabulary**" means cards in that language that aren't
  attached to any wordbox, not a separate table.

## Native-language vocabulary

A user can opt to build vocabulary **in their own native language** — e.g. a native Czech
speaker collecting Czech words/idioms they want to remember, with everything generated
monolingually (see "Call 2" in [ai-integration](ai-integration.md)). Enabled via the "Also
save words in my native language" checkbox on `/profile/edit`, under the native-language picker.

The implementation is intentionally minimal: enabling it just **attaches the native language to
the same `language_user` pivot** (with `users_level = null`, since a user isn't "learning" their
own language). That alone makes it a candidate for CALL 1's language detection and makes it show up
in `/cards`, `/base`, the Learn flow, and everywhere else that iterates `$user->languages()` —
there is no special-cased code in any of those paths. Two places *do* need to know about it
specifically:

- It's kept **separate from the 5-language learning cap** — the `max:5` validation rule in
  `UserController@update` applies only to `target_language_ids`, which explicitly excludes
  native.
- It's **hidden from the "Languages you are learning" table** on `/profile/edit`.
  `UserController@edit` derives the checkbox state (`nativeSaveEnabled`) from whether native is
  in the pivot and strips native out of the table data before passing it to the view;
  `UserController@update` re-adds native to the sync set when the box is checked.
- `Card::nativeLanguageFor()` returns `null` when the card's language **is** the user's native
  language, which is the flag every CALL 2 generator reads to write a monolingual card (no
  `translation` field, no CEFR steering). That is the only other generation-time special-casing.
  Its effect reaches CALL 1 too: with no native language to translate into, a base word's
  `translation` is asked for as a short same-language gloss instead.

### `/profile/edit` (`UserController@edit`/`@update`)

Also computes and shows **"hidden" languages**: ones the user has saved cards in
(`termCounts`, grouped by `language_id`) but is no longer actively learning — not a current
target, not native. These are shown greyed with an "Unhide" action so the user still sees every
language they have content in, without it cluttering the active learning set. Hidden state isn't
a separate DB column — it's derived on every page load from `termCounts` minus the current
target/native sets, so "hiding" a language is really just leaving it out of the submitted
`target_language_ids`.

`UserController@update` also: keeps `active_language_id` valid after a sync (falls back to the
first attached language if the previous active one was removed); and, when the submitted target set
is unambiguous (exactly one
language), adopts any pre-existing language-less cards/wordboxes/themes into it — a one-time
migration convenience for accounts that predate the language system.

## Which language a screen opens on

**Capture has no language input at all.** CALL 1 detects which of the learner's own languages the
Term is in, and staging is where a wrong detection gets corrected (see [cards](cards.md)
"Staging"). The vocabulary-base redesign therefore retired the save-destination picker, `POST
/capture-target` and the `capture_language_id`/`capture_wordbox_id` session keys outright — a
captured term also no longer lands in a chosen wordbox, so it starts in General vocabulary and is
moved from `/cards` if the learner wants it somewhere.

What remains is `User::currentSaveLanguage()`, which answers a narrower question: which language a
screen that shows one language at a time (`/cards`, `/base`, `/refresher`, the Learn builder) should
open on. It resolves `active_language_id` → first target language, and may return `null` for a
brand-new user with no languages set up. The name is now slightly wider than the job; every caller
passes an explicit `language_id` when the user has picked one.

## Shared wordbox picker component

`<x-wordbox-picker :target-languages :wordboxes-by-language :active-language-id :heading>`
(`resources/views/components/wordbox-picker.blade.php`) is the language switcher + wordbox tag
picker (general vocabulary | divider | wordbox tags + overflow "More" dropdown) used on the Learn
builder (`/setLearning`) and the vocabulary list (`/cards`). It is **self-contained and
page-agnostic**:

- It owns all of its own selection and overflow-layout JS (jQuery). The language switcher row
  itself only renders when the user has more than one target language.
- It exposes the current selection two ways so a consuming page never needs to reach into its
  DOM: `window.WordboxPicker.current()` → `{languageId, wordbox, label}`, and a
  `wordboxpicker:change` **DOM event** with the same detail fired on every change. Pages read
  `current()` on init (no dependency on event timing) and listen for the event afterward.
- The overflow layout (`layoutTagRow`) keeps "general vocabulary" and the divider **pinned** (they
  never overflow), keeps the **currently selected** wordbox tag visible even if that means
  demoting up to two other visible tags into the "More" dropdown to make room, and re-lays-out on
  window resize (debounced) and on language switch.
- Switching the active language tab is **pure client-side state** (just shows/hides the matching
  `.lang-group`) — no request round-trip, since all languages' wordbox lists are rendered
  up-front by the server.

**Gotcha**: never write the literal `<x-wordbox-picker>` tag inside a Blade `<script>` comment on
a consuming page — Blade parses component tags before the browser ever sees the `<script>` block,
so it gets rendered as a real (empty) component invocation instead of staying a comment.

## Queue jobs and language

Queue jobs run with no session and no `Auth` facade access, so they derive language from their
own data rather than from the current user's session state — e.g. `GenerateGapFillJob` reads
`$wordbox->language` (see [gap-fill](gap-fill.md)).
