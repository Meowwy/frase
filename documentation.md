# Frase — Project Documentation

A quick-start reference for picking up development on **Frase** in a new session. For the
authoritative coding standards and architecture, also read `CLAUDE.md` (project instructions
checked into the repo) — this file complements it with practical dev info and a session changelog.

## What the app is

Frase is a **language-learning app**. Users save words/phrases for spaced-repetition review
(flashcards) and get **AI-generated context** (example sentences, definitions, translations,
questions) to learn more effectively. It supports building active vocabulary in **up to 5 target
languages** per user.

## Tech stack

- **PHP 8.4**, **Laravel 12.x**
- **Frontend:** Tailwind CSS 3 (dark-mode-first, `bg-black text-white`), Blade anonymous
  components, **jQuery** (Ajax/DOM), Alpine.js (small interactions), **Vite** bundling
- **Database:** SQLite (default)
- **Notifications:** Toastr (client-side)
- **Queues:** Laravel queue system for slow AI tasks (e.g. `GenerateGapFillJob`)

## Dev environment notes (important)

- **Platform:** Windows 11. Run `php` / `artisan` / `pint` / `npm` via **PowerShell**
  (`php` is not on the Bash tool's PATH). The Bash tool is fine for POSIX/file operations.
- **Vite hot reload:** CLAUDE.md says "no need to run `npm run build`" because of hot reload,
  **but the dev server (`npm run dev`) is often NOT running** in practice. When `public/hot`
  is absent, the app serves the last **built** CSS/JS from `public/build/`. In that case CSS/JS
  changes only appear after `npm run build`. Check for `public/hot` to know which mode you're in.
- **PHPUnit suite is currently broken** — don't rely on `php artisan test` for verification.
  Verify instead via **Tinker** (render a view / run a query) and **Pint** for formatting.
- **OpenAI key:** read via `config('services.openai.secret')`. Never hardcode or log it.

## MCP server: `laravel-boost`

This project has the **laravel-boost** MCP server available — use it for fast, accurate
introspection instead of guessing. Key tools (fetch schemas via ToolSearch
`select:<name>` before calling):

- `mcp__laravel-boost__tinker` — run PHP in the app context (great for verifying model/query logic).
- `mcp__laravel-boost__database-query` — read-only SQL against the app DB.
- `mcp__laravel-boost__database-schema` / `database-connections` — inspect tables/columns.
- `mcp__laravel-boost__list-routes` — enumerate routes (web + api).
- `mcp__laravel-boost__list-artisan-commands`, `get-config`, `list-available-config-keys`,
  `list-available-env-vars`.
- `mcp__laravel-boost__read-log-entries`, `last-error`, `browser-logs` — debugging.
- `mcp__laravel-boost__search-docs` — version-specific Laravel ecosystem docs. Prefer this for
  framework questions over general recall.
- `mcp__laravel-boost__get-absolute-url` — build correct local URLs.

(There is also a Svelte MCP server registered, but this project is Blade/jQuery — not used here.)

## Architecture cheatsheet

- **Routing:** `routes/web.php` (mix of controllers + closures), `routes/api.php`
  (Sanctum token-protected, used by the browser extension).
- **Controllers:** `app/Http/Controllers` — e.g. `AjaxController` (capture flow),
  `CardController`, `WordboxController`, `UserController`, `ThemeController`.
- **Models:** `app/Models` — `Card`, `Wordbox`, `Theme`, `Language`, `User`, `AI`, `Learning`,
  `GapFillExercise`. `$guarded = []` (mass-assignment open). Relationships typed.
- **AI:** encapsulated in `App\Models\AI`. Chat uses model in `AI::MODEL` (`gpt-5.4-nano`) with
  `reasoning_effort` = `AI::REASONING_EFFORT` (`low`); embeddings use `text-embedding-3-small`.
  Reasoning models reject `temperature`. All structured output uses strict `json_schema`.
  Flashcard example sentences wrap the term once as `[term]` (the blanking regex is `/\[.*?\]/`).
- **Views:** `resources/views`, components in `resources/views/components`. Base layout is
  `<x-html-layout>`; forms via `<x-forms.*>`; UI via `<x-panel>`, `<x-card>`, `<x-section-heading>`.

### Multi-language model (key tables)

- `languages` — static seeded reference list (ISO 639-1 `code`, `name`, `native_name`, `flag`
  emoji), populated by `LanguageSeeder` (idempotent on `code`). Add languages there.
- `language_user` pivot — a user's up-to-5 target languages (`$user->languages()`).
- `users.native_language_id`, `users.active_language_id` — FK defaults (supersede legacy
  free-text `target_language` / `native_language` columns kept temporarily for backfill).
- `cards`, `wordboxes`, `themes` each carry `language_id`. "General vocabulary" = cards in a
  language not attached to any wordbox.
- **Save destination** (where a new word goes: language + optional wordbox) is chosen in the
  dashboard right-side picker and stored in session (`capture_language_id`, `capture_wordbox_id`)
  via `POST /capture-target`. `User::currentSaveLanguage()` resolves
  session → `active_language_id` → first target language.
- Queue jobs have no session/Auth, so they derive language from their own data
  (e.g. `GenerateGapFillJob` uses `$wordbox->language`).

## Browser extension

A separate Chrome extension lives at **`D:\3 Resources\Frase_extension`** (not in this repo).
Files: `manifest.json` (MV3), `frase_popup.html`, `popup.js`, `frase_logo.png`. It authenticates
with **Sanctum tokens** (stateless — no session) against `https://frase.cz/api`:

- `POST /api/extension/login` → returns a token (stored in `chrome.storage.local`).
- `POST /api/addWordAPI` (`AjaxController@index`) → captures a word; honors `language_id` and
  `wordbox_id` in the JSON body.
- `GET /api/save-options` → returns the save-destination dropdown options (see changelog).

Because the extension is stateless, it must pass `language_id` / `wordbox_id` explicitly on
each capture (no session fallback like the website has).

---

## Session changelog (2026-06-15)

### 1. Flag emoji rendering (website)
**Problem:** `languages.flag` stores real emoji (🇬🇧 etc.) but Windows has no flag-emoji font,
so all browsers render the two regional-indicator letters ("GB", "CZ"). An earlier Twemoji+jsDelivr
approach caused a "GB" flash before SVGs loaded and required a network/CDN fetch per flag.

**Final solution — locally bundled, base64-inlined flags-only webfont (no CDN, no flash):**
- Created `resources/css/flags.css` — a single `@font-face` for `"Twemoji Country Flags"` with
  the 78 KB `TwemojiCountryFlags.woff2` (from npm `country-flag-emoji-polyfill`) **base64-inlined**
  as a data URI, `font-display: block`. Glyphs are present at CSS-parse time → no async fetch, no flash.
- `resources/css/app.css` — added `@import './flags.css';` as the first line.
- `tailwind.config.js` — set the `font-lato` stack to
  `['"Twemoji Country Flags"', "Lato", "sans-serif"]`. The flag font holds only flag glyphs, so
  Latin text falls through to Lato via per-glyph fallback. Body uses `font-lato`, so flags render
  everywhere (including JS-built DOM) with no MutationObserver.
- `resources/views/components/html-layout.blade.php` — removed the Twemoji `<script>`, `.emoji`
  CSS, and the parse/observer JS.
- Rebuilt CSS (`npm run build`). Cost: ~+104 KB CSS (~85 KB gzip, since woff2 is pre-compressed).
- **Note:** other CDN assets remain externally loaded (toastr, jQuery, Google Fonts Lato,
  SortableJS) — not localized this session.

### 2. `/profile/edit` combo boxes
- `resources/views/user/edit.blade.php` — both custom dropdown menus changed from
  `max-h-60 overflow-auto` → `max-h-48 overflow-y-auto`, keeping `bg-neutral-900` (opaque,
  bounded, scrollable). Root cause of the original "transparent/unbounded" report was stale
  served CSS (dev server down) — fixed by rebuilding.

### 3. Browser extension: save-destination dropdown
Added a top-right dropdown in the extension popup to choose where a captured word is saved
(language + wordbox, or "general" = no wordbox). Saves to General vocabulary for the
no-wordbox option.

- **Backend** — `routes/api.php`, new `GET /api/save-options` (Sanctum group). Returns one flat,
  pre-ordered option list. Each option: `{ value, label, language_id, wordbox_id }` where
  `value` = `"<language_id>:<wordbox_id>"` (`"<id>:"` = general / no wordbox). Plus `selected`
  defaulting to the user's `currentSaveLanguage()` general option.
  **Ordering:** languages alphabetically; within each language the `"… - general"` option first,
  then its wordboxes A–Z. Labels are plain text like `"English - general"` /
  `"English - Travel"` (no flag emoji, to avoid the Windows rendering issue inside the extension).
- **Extension** (`D:\3 Resources\Frase_extension`):
  - `frase_popup.html` — added `<select id="saveTarget">` in a flex header (top-right of main view) + styling.
  - `popup.js` — `loadSaveOptions()` fetches `/save-options` on showing the main view and fills the
    dropdown (preselecting `selected`); on send, splits the chosen `value` into `language_id` +
    `wordbox_id` and includes both in the `addWordAPI` body. 401 forces re-login; fetch failure
    leaves the dropdown empty and the server falls back to the user's default language.
- **No `AjaxController` change needed** — it already validates/honors `language_id` & `wordbox_id`,
  and a null `wordbox_id` routes to general vocabulary.

### 4. Card detail page: language flag + wordbox link
- `CardController@show` — eager-loads the card's `language` and resolves its first `wordbox`
  (`$card->wordbox()->first()`), passing `$wordbox` to the view.
- `resources/views/cards/show.blade.php`:
  - **Main Term Section** is now a flex row; the language flag (`$card->language->flag`, rendered
    via the bundled flags webfont like elsewhere) sits right-aligned at `text-xl` (same size as the
    translation text).
  - **Definition Section** — the old theme-name pill is replaced by the wordbox name rendered as a
    clickable pill linking to `route('wordbox.show', $wordbox->id)`. Hidden when the card has no wordbox.

### 5. Flashcard prompts: phrase-first consistency (single call)
- **Finding:** OpenAI Structured Outputs is autoregressive and emits JSON in the **exact
  property order of the schema**, so each field is generated conditioned on the fields above it
  (the same reason OpenAI's own example puts `steps` before `final_answer`). Because `phrase` is
  the **first** property in the card schema, `sentence`/`question`/`translation`/`definition`/`theme`
  are all generated *after* it and stay consistent with it. **No second call is needed** — a single
  call already gives "decide the phrase, then derive everything from it." The only limitation is that
  the model cannot revise `phrase` based on a later field (generation only flows forward), which is the
  desired direction anyway. Keep `phrase` as the first property.
- **Change** (`app/Models/AI.php`, both `getContentForCard` and `getContentForCardWithContext`):
  rewrote the system message and all field descriptions to (a) say the model picks the `phrase`
  first and every other field must describe **that phrase, not the raw Term**, and (b) replace the
  leftover "the term" wording with "the phrase" so the dependency is explicit. Prompts were also
  tightened (shorter, no behaviour change to the schema/field set). Field order unchanged.

### 6. Gap-fill exercises: word bank, AI title, delete
- **Word bank (`gap-fill/show.blade.php`):** the old feedback-only "Answer Key" is now a permanent
  **"Words to use"** bank below the story — all answers shuffled (`collect()->values()->shuffle()`),
  no index labels. Chips are click-to-fill (fills the last-focused gap, else the first empty one).
- **Re-checkable feedback:** replaced the single `showFeedback` boolean with a reactive `feedback`
  map keyed by gap index. `check()` recomputes every click (so users can fix and recheck); each
  input's `@input` resets its own colour to default the moment its value changes.
- **AI title:** `AI::generateTextWithGaps` now returns a `title` (≤5 words, target language, derived
  from the story — placed after `text` in the schema so it's conditioned on it). `GenerateGapFillJob`
  persists it. New nullable `gap_fill_exercises.title` column
  (`2026_06_15_133302_add_title_to_gap_fill_exercises_table`); added to the model `$fillable`.
  Shown as the exercise `<h1>` and in the history list (falls back to `Exercise #id` for old rows).
- **Delete:** new `DELETE /gap-fill/{exercise}` → `GapFillExerciseController@destroy` (redirects to
  the wordbox). History table has a red **Delete** action opening an Alpine confirmation modal.
- **Prompt note (same session):** the gap-fill prompt no longer requires verbatim phrases — the
  story may adapt a phrase's form (inflection/variant) while keeping its meaning/context; the
  returned `answers[].phrase` is the exact text that fills the gap.

### Verification used this session
- Tinker (render views, build the option list for a real user with wordboxes to confirm ordering).
- `php artisan route:list --path=api` to confirm `/api/save-options` registered.
- `vendor/bin/pint` on changed PHP (clean).
- PHPUnit not used (suite broken).
