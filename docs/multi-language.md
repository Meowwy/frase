# Multi-language Vocabulary

How Frase supports learning several languages at once, the native-language-vocabulary opt-in, and
the shared UI component that switches between languages in place.

## Data model

- **`languages`** — a static, seeded reference list (ISO 639-1 `code`, English `name`,
  `native_name`, emoji `flag`), populated by `LanguageSeeder` (idempotent `updateOrCreate` on
  `code`, ~42 languages). Add a language there, not ad hoc.
- **`language_user` pivot** (`$user->languages()`) — the user's **target-language set, up to 5**.
  Carries `users_level` (nullable CEFR string `A1`..`C2`, allowed values + AI-facing descriptions
  in `config/proficiency.php`) via `->withPivot()`.
  `User::levelForLanguage($language)` reads the level for a given language.
- **`users.native_language_id`**, **`users.active_language_id`** — single-value FKs.
  `native_language_id` is the user's own language (used for translations, see
  [ai-integration](ai-integration.md)). `active_language_id` is the language single-language screens
  **open on** (see "Which language a screen opens on" below) — not a save destination: capture has
  none any more.
- These FKs **supersede** the legacy free-text `users.target_language` / `users.native_language`
  string columns, which are kept temporarily for backfill and are still what
  `RegisteredUserController@store` writes on signup — see [overview](overview.md) "Known rough edges". A
  brand-new user has no pivot rows and no `native_language_id` until they visit `/profile/edit`.
- `cards`, `wordboxes`, and `themes` each carry their own `language_id` — a card belongs to
  exactly **one** language, and a wordbox holds only base words of its own.
- A feature only some languages have is **derived from the language guideline**, never a flag on
  `languages`: Frammenti's [article drill](frammenti.md#article-drill) exists for a language whose
  guideline gives nouns a gender (`ArticleDrill::available()`).

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
  language, which is the flag every CALL 2 generator reads to write a monolingual card (no translation of the Term; with no new words either, no AI call at all). That is the only other generation-time special-casing.
  Its effect reaches the new words too: with no native language to translate into, a base word's
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
/capture-target` and the `capture_language_id`/`capture_wordbox_id` session keys outright.

What remains is `User::currentSaveLanguage()`, which answers a narrower question: which language a
screen that shows one language at a time (`/cards`, `/base`, `/frammenti`, the Learn builder) should
open on. It resolves `active_language_id` → first target language, and may return `null` for a
brand-new user with no languages set up. The name is now slightly wider than the job; every caller
passes an explicit `language_id` when the user has picked one.

## Shared language switcher component

`<x-language-switcher :target-languages :active-language-id>`
(`resources/views/components/language-switcher.blade.php`) is the row of language tabs on the
pages that re-filter in place — the Learn builder (`/setLearning`) and the vocabulary list
(`/cards`). (`/base` and Frammenti switch language with plain links instead, a page load each.) It
is **self-contained and page-agnostic**:

- It only renders when the user has more than one target language.
- Switching tabs is **pure client-side state** — no request of its own. It fires a
  `languageswitcher:change` **DOM event** (`{languageId}`), and the page decides what to do with
  it (re-fetch its rows, rebuild its mode links). Pages start from their own server-rendered
  `activeLanguageId`, so there is nothing to read on init.

**Gotcha**: never write a literal component tag (`<x-language-switcher>`) inside a Blade
`<script>` comment on a consuming page — Blade parses component tags before the browser ever sees
the `<script>` block, so it gets rendered as a real (empty) component invocation instead of
staying a comment.

## Queue jobs and language

Queue jobs run with no session and no `Auth` facade access, so they derive language from their
own data rather than from the current user's session state — e.g. `GenerateGapFillJob` reads
`$wordbox->language` (see [gap-fill](gap-fill.md)).
