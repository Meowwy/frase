# Overview & Working Conventions

What Frase is, the stack it's built on, and the conventions that apply everywhere in the
codebase — routing style, controller/model split, coding standards, and known dev-environment
gotchas. Read this first; the other files in `docs/` go deep on one feature area each.

## What the app is

Frase is a **language-learning app**. Users save words and phrases they encounter, get
**AI-generated context** for them (example sentences, definitions, translations, usage
fragments), and review them later via spaced-repetition flashcards and AI conversation practice.
A user can build active vocabulary in **up to 5 target languages**, plus optionally their own
native language (see [multi-language](multi-language.md)).

The core loop is: **capture** a word/phrase → AI turns it into a flashcard (see
[ai-integration](ai-integration.md), [cards](cards.md)) → the word enters an SRS queue → the user reviews it via one of
several **learning modes** (see [learning-flow](learning-flow.md)) or practises it in a live AI conversation (see
[conversation-challenge](conversation-challenge.md), [conversation-voice](conversation-voice.md), [conversation-game](conversation-game.md)).

## Core technologies

- **PHP 8.4**, **Laravel 12.x**
- **Frontend**: Tailwind CSS 3, Blade anonymous components, **jQuery** (DOM/Ajax, loaded from a
  CDN in `html-layout.blade.php`, not via npm), a small **Alpine.js** import in
  `resources/js/app.js` (`window.Alpine`) for lightweight interactions (e.g. confirmation
  modals), **Vite** for bundling `resources/css/app.css` + `resources/js/app.js`
- **Notifications**: Toastr (CDN), used for client-side success/error toasts
- **Database**: SQLite (default)
- **Queues**: Laravel's queue system for slow AI calls (gap-fill generation; card creation is
  synchronous — see [ai-integration](ai-integration.md))

## Core guidelines

- Write idiomatic, consistent Laravel — match the conventions already in the file you're editing
  before reaching for a "more correct" pattern. If you spot code that doesn't follow modern
  Laravel conventions, it's fine to suggest fixing it, but don't rewrite it as a side effect of
  an unrelated change.
- Prefer the simplest solution that solves the actual problem. Don't add abstraction, config
  flags, or defensive handling for cases that can't occur here.
- Whenever you change the application's architecture (new table, new endpoint, new convention,
  a rule a prompt depends on), update the relevant file in `docs/` — these files are the only
  record of *why* the app works the way it does; the code alone won't tell a future contributor
  that, say, the level description in `config/proficiency.php` deliberately avoids the word
  "short". See `docs/README.md` (via `CLAUDE.md`) for which file covers what.
- **You don't need to run `npm run build`** during normal development — Vite's dev server
  (`npm run dev`) hot-reloads. **Exception**: previewing via the `frase.test` Herd environment
  when the dev server is *not* running. `@vite` then falls back to the last build in
  `public/build/`; if that's stale, recently added Tailwind classes are silently missing and the
  page looks broken (this doesn't happen in production because deploy always runs a fresh
  build). Check for a `public/hot` file to know which mode is active — if it's missing, either
  start `npm run dev` or run `npm run build` before previewing.
- Windows/PowerShell note: `php`, `artisan`, `pint`, and `npm` are not on the Bash tool's PATH —
  run them via PowerShell. Bash is fine for POSIX/file operations.
- The `OPENAI_SECRET` is read via `config('services.openai.secret')`. Never hardcode or log it.
  Client-facing voice sessions use a short-lived *ephemeral* secret minted server-side instead —
  see [conversation-voice](conversation-voice.md).

## Routing

- All routes are defined in `routes/web.php` (session-authenticated, `auth`/`guest` middleware
  groups) and `routes/api.php` (Sanctum token-authenticated, used by the browser extension — see
  [browser-extension](browser-extension.md)).
- Routing is a **mix of controller actions and closures**. Controllers are used for anything with
  real logic or multiple steps (`CardController`, `ChallengeController`, `Learning` static
  methods called directly as route actions); simple CRUD-ish or one-off logic is often left as a
  closure directly in `web.php` (e.g. the `/` dashboard, the `/kresleni*` bonus routes). This is
  inconsistent by history rather than by design — new non-trivial logic should go in a controller.
  (An inline closure that updated a card by id used to live here too; it's been folded into
  `CardController::update()` so the ownership check described in [cards](cards.md) applies to it
  — see "Known rough edges" below for the history.)
- Some routes bypass a controller class entirely and call a **static method on a model** directly
  as the route action (`Learning::setLearning`, `Learning::startLearning`,
  `Learning::startLearningSet` — see [learning-flow](learning-flow.md)). This is an established pattern for the
  learning-session bootstrap flow specifically; it isn't used elsewhere.
- Route registration order matters where a literal path could otherwise be swallowed by a
  wildcard: e.g. `POST /cards/bulk-destroy` and `/cards/assign-wordbox` are registered *before*
  `POST /cards/{card:id}` for this reason.
- Legacy/duplicate routes exist from earlier iterations of a feature and are kept because other
  code still links to them (see [cards](cards.md) for `theme=` query links, [learning-flow](learning-flow.md) for the
  theme-based `/filterCardsForLearning/{filter}` entry point). Don't assume a route with an odd
  name is dead — check for inbound links first.

## Controllers & models

- Controllers live in `app/Http/Controllers`, PascalCase + `Controller` suffix
  (`CardController`). Note the historical typo `SeachController` (missing the "r") — it is the
  real, routed controller for `/search`; don't "fix" the class name without also fixing every
  route/import.
- Validation is usually done inline with `$request->validate()`; a few endpoints use Form
  Request classes (`StoreCardRequest`, `UpdateCardRequest`, `StoreWordboxRequest` in
  `app/Http/Requests`). `StoreCardRequest`/`UpdateCardRequest` back the two live manual
  card-creation/edit paths (`CardController::save`/`update`, see [cards](cards.md)) — both
  `authorize(): true` (ownership is checked separately via a Policy, see below — a Form Request's
  `authorize()` doesn't have reliable access to a not-yet-route-bound model) and real `rules()`.
  `AjaxController@index` (the AI-assisted capture path) validates inline instead, since its rules
  depend on runtime state (native vs. target language) a static Form Request can't express as
  cleanly.
- **Authorization**: ownership checks go through Laravel Policies, not ad hoc `if` statements.
  `app/Http/Controllers/Controller.php` includes the `AuthorizesRequests` trait, so any controller
  can call `$this->authorize('ability', $model)` (throws a 403 automatically) once a matching
  `App\Policies\{Model}Policy` exists — Laravel resolves the policy by naming convention, no
  explicit registration needed. `CardPolicy`, `WordboxPolicy`, and `GapFillExercisePolicy` exist
  and are used this way. A closure route that needs the same check (not every route is worth
  promoting to a controller method for this alone) uses `abort_unless(Auth::user()->can('ability',
  $model), 403)` instead — same policy, same effect. **A few older per-linking endpoints on
  `CardController`** (`linkSearch`, `link`, `unlink`, `saveNote`) still use a hand-rolled
  `abort_unless($card->user_id === Auth::id(), 403)` rather than the policy — functionally
  equivalent, just written before the policy existed; new authorization checks should use the
  policy form.
- Business logic that spans a whole feature (not just "read/write one row") is often placed as
  static methods on a model instead of a controller when the model already owns the relevant
  state — `App\Models\AI` (all OpenAI calls, see [ai-integration](ai-integration.md)) and `App\Models\Learning`
  (SRS scheduling + learning-session bootstrap, see [learning-flow](learning-flow.md)) are the two big examples.
  This is a deliberate, established pattern in this codebase — follow it for similar
  feature-level logic rather than introducing a new service-class layer.
- Eloquent models live in `app/Models`. Mass assignment is generally left open
  (`protected $guarded = [];`) rather than maintaining a `$fillable` allowlist — match this in
  new models unless there's a specific reason to lock a model down.
- Relationships are typed where practical (`: BelongsTo`, `: HasMany`) in newer models; older
  ones omit the return type. Prefer typed return types in new relationship methods.
- Several controllers still carry the full stock Laravel resource-controller skeleton
  (`index`/`create`/`store`/`show`/`edit`/`update`/`destroy`) with most methods empty (`TagController`,
  `ThemeController`, `WordboxController`, `UserController`). Don't assume an empty method is a bug
  — it's usually just unused scaffolding from `php artisan make:controller --resource`.

## Coding standards

- PHP 8+ features are used where they help (constructor property promotion in jobs, typed
  properties, match expressions in `Learning::renderLearningView`). Follow PSR-12.
- Run `vendor/bin/pint` to format PHP before considering a change done.
- Naming: Controllers `PascalCaseController`; Models `PascalCase`; Views `kebab-case` or
  `snake_case` (`add.blade.php`, `html-layout.blade.php`); Blade components `kebab-case`
  (`section-heading.blade.php`).
- The UI is English-only text, but the app is functionally multilingual per user — every
  user-facing AI field is generated in either the target or native language depending on the
  field and the user's settings (see [ai-integration](ai-integration.md), [multi-language](multi-language.md)). Don't assume a
  hardcoded English string is safe for a field the AI writes.
- Always handle AI failures gracefully: every `AI::*` call can return `null` (refusal, non-2xx
  response, or an unexpected shape) and callers must check for it rather than blindly
  `json_decode`-ing and dereferencing. Log with `Log::error`/`logger()` and surface a plain
  message to the user — never a stack trace.
- Every form uses `@csrf`; views gate on `@auth`/`@guest` for conditional rendering.

## Known rough edges (don't be surprised by these)

- **Queued jobs don't run in production yet.** `GenerateGapFillJob` and `GenerateEmbeddingJob` are
  dispatched onto `QUEUE_CONNECTION=database` (confirmed in `fly.toml` and `.env`), but there is
  **no `queue:work`/`queue:listen` process defined anywhere in the deploy config**
  (`Dockerfile`/`.fly/supervisor/conf.d` only run `php-fpm` and `nginx`). Until a worker process
  is added there, both jobs sit in the `jobs` table and never execute in production — gap-fill
  generation will poll forever and embeddings are never generated. Locally this only matters if
  you're testing either feature without `php artisan queue:work` running. See
  [gap-fill](gap-fill.md).
- `RegisteredUserController@store` still validates against the legacy free-text
  `targetLanguage`/`nativeLanguage`/`code` fields (there's a hardcoded invite `code` = `delina`)
  rather than the `languages`/`language_user` model introduced later (see [multi-language](multi-language.md)).
  Registration does not yet set `native_language_id` / attach a target language via the pivot —
  a new user has to do that on `/profile/edit` before capturing works
  (`AjaxController@index` redirects there if `currentSaveLanguage()` is null).
- **History note, not a current bug**: `CardController@store` (an old AI-calling variant of card
  creation, superseded by `AjaxController@index`) and the jobs `CreateCardJob`/`CallAIJob`
  (referenced the dropped `question` column, unreachable) have been deleted outright rather than
  kept as dead code. `CardController::save()` — the manual "/add" entry point, no AI involved —
  used to be *shadowed by a duplicate route registration* (a second, working, closure-based
  `/cards/new` handler was silently unreachable because Laravel matches the first-registered
  route) and its old body referenced an undefined property; both are fixed now, and `save()` is
  the one real handler. `CardController@show`/`@edit` and the update route used to have no
  ownership check at all; they now go through `CardPolicy` (see the Authorization note above).
