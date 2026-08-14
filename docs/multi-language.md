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
  [ai-integration](ai-integration.md)). `active_language_id` is the **durable default save language** — the last
  one explicitly chosen via the capture-target picker (see below).
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
monolingually (see `AI::getContentForCardNative` in [ai-integration](ai-integration.md)). Enabled via the "Also
save words in my native language" checkbox on `/profile/edit`, under the native-language picker.

The implementation is intentionally minimal: enabling it just **attaches the native language to
the same `language_user` pivot** (with `users_level = null`, since a user isn't "learning" their
own language). That alone makes it a valid save destination and makes it show up in `/cards`, the
Learn flow, and everywhere else that iterates `$user->languages()` — there is no special-cased
code in any of those paths. Two places *do* need to know about it specifically:

- It's kept **separate from the 5-language learning cap** — the `max:5` validation rule in
  `UserController@update` applies only to `target_language_ids`, which explicitly excludes
  native.
- It's **hidden from the "Languages you are learning" table** on `/profile/edit`.
  `UserController@edit` derives the checkbox state (`nativeSaveEnabled`) from whether native is
  in the pivot and strips native out of the table data before passing it to the view;
  `UserController@update` re-adds native to the sync set when the box is checked.
- The AI branch in `AjaxController@index` (native destination → `getContentForCardNative`) is the
  only other save-time special-casing.

### `/profile/edit` (`UserController@edit`/`@update`)

Also computes and shows **"hidden" languages**: ones the user has saved cards in
(`termCounts`, grouped by `language_id`) but is no longer actively learning — not a current
target, not native. These are shown greyed with an "Unhide" action so the user still sees every
language they have content in, without it cluttering the active learning set. Hidden state isn't
a separate DB column — it's derived on every page load from `termCounts` minus the current
target/native sets, so "hiding" a language is really just leaving it out of the submitted
`target_language_ids`.

`UserController@update` also: keeps `active_language_id` valid after a sync (falls back to the
first attached language if the previous active one was removed, clearing the capture-target
session keys in that case); and, when the submitted target set is unambiguous (exactly one
language), adopts any pre-existing language-less cards/wordboxes/themes into it — a one-time
migration convenience for accounts that predate the language system.

## Save destination (capture target)

Where a newly captured word is saved — a language plus an optional wordbox — is chosen in the
right-side picker on the dashboard and persisted in the **session**
(`capture_language_id`, `capture_wordbox_id`) via `POST /capture-target`
(`AjaxController@setCaptureTarget`). `User::currentSaveLanguage()` resolves it in this order:
session selection → `active_language_id` → first target language (may return `null` for a
brand-new user with no languages set up).

Choosing a language there also **updates `active_language_id`** — the session value is the
per-tab/per-visit override, `active_language_id` is what persists across sessions/devices. The
picker controls **only** where new words are saved; it does not reload or re-scope the dashboard
itself, which stays cross-language by design.

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
