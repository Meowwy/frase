# Overview & Working Conventions

What Frase is, the stack it's built on, and the conventions that apply everywhere in the
codebase — routing style, controller/model split, coding standards, and known dev-environment
gotchas. Read this first; the other files in `docs/` go deep on one feature area each.

## What the app is

Frase is a **language-learning app**. Users save words and phrases they encounter, get
**AI-generated context** for them (translations and definitions), and review them later via spaced-repetition flashcards and AI conversation practice.
A user can build active vocabulary in **up to 5 target languages**, plus optionally their own
native language (see [multi-language](multi-language.md)).

The core loop is: **capture** a word/phrase → it waits in **staging** as a proposal while the AI
works out what it is → the learner **approves** it, which writes the card and adds its words to
their **vocabulary base** (see [ai-integration](ai-integration.md), [cards](cards.md)) → the card
enters an SRS queue → the user reviews it via one of several **learning modes** (see
[learning-flow](learning-flow.md)) or practises it in a live AI conversation (see
[conversation-challenge](conversation-challenge.md), [conversation-voice](conversation-voice.md),
[conversation-game](conversation-game.md)).

The app divides into three modules without residue — **CAPTURE**, **ORGANIZE**, **LEARN** — and the
nav is grouped by them. See `CONTEXT.md` and [features-overview](features-overview.md).

## Core technologies

- **PHP 8.4**, **Laravel 12.x**
- **Frontend**: Tailwind CSS 3, Blade anonymous components, **jQuery** (DOM/Ajax, loaded from a
  CDN in `html-layout.blade.php`, not via npm), a small **Alpine.js** import in
  `resources/js/app.js` (`window.Alpine`) for lightweight interactions (e.g. confirmation
  modals), **Vite** for bundling `resources/css/app.css` + `resources/js/app.js`
- **Notifications**: Toastr (CDN), used for client-side success/error toasts
- **Database**: SQLite (default)
- **Queues**: Laravel's queue system for slow AI calls — gap-fill generation, and CALL 1 at
  capture (`AnalyzeProposalJob`, see [cards](cards.md) "Staging"). CALL 2 at proposal approval is
  synchronous, since the learner is waiting on the card it writes

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
- Some routes bypass a controller class entirely and call a **static method on `Learning`** directly
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
- **Route names must be unique across `web.php` and `api.php`.** `php artisan route:cache` now
  runs at image build time (see [Deployment](#deployment-flyio)), and a duplicate `->name()`
  makes it throw `LogicException: ... Another route has already been assigned name [...]`, which
  fails the whole `fly deploy`. This is deliberate — it used to fail silently at boot instead,
  leaving production permanently un-cached. The web and API capture endpoints share a controller
  but not a name: `capture` (web, `POST /capture`) vs. `captureApi` (`POST /api/addWordAPI`) —
  and `ProposalController@store` reads `routeIs('captureApi')` to set the proposal's `source`, so
  those names are load-bearing, not just labels.

## Deployment (Fly.io)

Deployment follows the [build/release/run](https://12factor.net/build-release-run) split, so that
a cold start does as little work as possible. Which bucket a step belongs in comes down to one
question: **does it depend only on source code, or on the environment it runs in?**

- **Build time** (`Dockerfile` step 4, baked into the image): `composer install
  --optimize-autoloader --no-dev`, then `optimize:clear` followed by `route:cache`, `view:cache`
  and `event:cache`. These are pure functions of the source, so they are identical on every
  machine and every boot. They are chained with `&&`, so a broken route or Blade template fails
  the deploy rather than the running site.
- **Boot time** (`.fly/scripts/`, run by `.fly/entrypoint.sh` in alphabetical order):
  `00_storage_init.sh` (volume layout), `caches.sh` → **`config:cache` only**, `db.sh` →
  `migrate --force`.
  - `config:cache` cannot move to build time: it freezes `env()` values into
    `bootstrap/cache/config.php`, and Fly secrets (`APP_KEY`, `OPENAI_API_KEY`, …) only exist on
    the running machine. Caching it during build would bake in `null`.
  - `migrate` cannot move to a Fly `[deploy] release_command` either: release machines run with
    **no volumes attached**, and the SQLite database lives on the `storage_dir` volume, so it
    would migrate a throwaway file.
- `.fly/entrypoint.sh` runs each script as `bash -e "$f" || exit 1`. It previously read
  `bash "$f" -e`, which passes `-e` to the script as a positional argument instead of enabling
  `errexit` — that is how the broken `route:cache` went unnoticed. A boot script failing now
  stops the machine from starting, which surfaces in `fly logs` instead of silently degrading.
- **OPcache** caches compiled PHP bytecode in shared memory so the ~9k files of `vendor/` and
  `app/` are not re-parsed on every request. It is a *separate* apt package
  (`php8.4-opcache` in `.fly/php/packages/8.4.txt`) and the image installs with
  `--no-install-recommends`, so it has to be listed explicitly or it is simply absent.
  Tuning lives in `.fly/fpm/conf.d/99-opcache.ini`, which is copied into
  `/etc/php/8.4/fpm/conf.d/`. **The `99-` prefix matters**: apt's own `10-opcache.ini` carries
  the `zend_extension=opcache.so` line, so a file named `10-opcache.ini` would replace it and
  silently disable OPcache while appearing to configure it.
  - `opcache.validate_timestamps=0` is safe *because* the image is immutable and `config:cache`
    completes before supervisor starts php-fpm. It drops a `stat()` per included file per
    request. The trade-off is that code changes only take effect via a new deploy.
  - `opcache.save_comments=1` must stay: Laravel and several packages read docblocks by
    reflection.
  - JIT is left off (`opcache.jit_buffer_size` defaults to 0). This app is I/O-bound on SQLite
    and the OpenAI API, so JIT would add risk for no measurable gain.
- **The queue worker** (`.fly/supervisor/conf.d/worker.conf`) runs `queue:work` beside php-fpm
  and nginx, as `www-data` like php-fpm so it can write the SQLite file. Capture depends on it
  (`AnalyzeProposalJob`), as do gap-fill and embeddings. `--timeout=85` stays under the database
  queue's 90-second `retry_after`, so a slow OpenAI call is never picked up twice. It suspends with
  the machine, which is harmless: jobs are only dispatched by requests, which wake it. Locally, run
  `php artisan queue:work` yourself.
- `fly.toml` uses `auto_stop_machines = 'suspend'`, which restores the machine from a RAM
  snapshot and **skips the entrypoint entirely** on resume. The boot-time work above therefore
  only runs on the first boot after a deploy, after a crash, or when Fly evicts a suspended
  machine to fully stopped.

## Controllers & models

- Controllers live in `app/Http/Controllers`, PascalCase + `Controller` suffix
  (`CardController`).
- Validation is usually done inline with `$request->validate()`; a few endpoints use Form
  Request classes (`StoreCardRequest`, `UpdateCardRequest`, `StoreWordboxRequest` in
  `app/Http/Requests`). `StoreCardRequest`/`UpdateCardRequest` back the two live manual
  card-creation/edit paths (`CardController::save`/`update`, see [cards](cards.md)) — both
  `authorize(): true` (ownership is checked separately via a Policy, see below — a Form Request's
  `authorize()` doesn't have reliable access to a not-yet-route-bound model) and real `rules()`.
  `ProposalController` validates inline instead: capture has only two fields, and the staging
  actions are one field each, so a Form Request per endpoint would be more ceremony than rule.
- **Authorization**: ownership checks go through Laravel Policies, not ad hoc `if` statements.
  `app/Http/Controllers/Controller.php` includes the `AuthorizesRequests` trait, so any controller
  can call `$this->authorize('ability', $model)` (throws a 403 automatically) once a matching
  `App\Policies\{Model}Policy` exists — Laravel resolves the policy by naming convention, no
  explicit registration needed. `CardPolicy`, `WordboxPolicy`, and `GapFillExercisePolicy` exist
  and are used this way. A closure route that needs the same check (not every route is worth
  promoting to a controller method for this alone) uses `abort_unless(Auth::user()->can('ability',
  $model), 403)` instead — same policy, same effect.
- Business logic that spans a whole feature (not just "read/write one row") is often placed as
  static methods on a model instead of a controller when the model already owns the relevant
  state. `App\Models\AI` (all OpenAI calls, see [ai-integration](ai-integration.md)) and `App\Models\Learning`
  (SRS scheduling + learning-session bootstrap, see [learning-flow](learning-flow.md)) are the two big examples
  — plain classes of static methods living in `app/Models`, not Eloquent models (they have no table).
  `Proposal::approve()` and `BaseWord::resolve()` follow the same pattern. This is a deliberate,
  established pattern in this codebase — follow it for similar feature-level logic rather than
  introducing a new service-class layer.
- **`app/Support/`** holds the one thing that is neither: `LanguageGuideline`, a plain class that
  reads declarative per-language data out of `resources/language-guidelines/`. There is no row
  behind it, so it is not a model, and it is stateless data access, so it is not a service layer.
  See [ai-integration](ai-integration.md) "Language guidelines".
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

- **The Swedish lexicon has to be imported by hand, and production has none yet.** `lexicon_entries`
  is filled by `php artisan lexicon:import-saldo <path-to-saldom.xml>` (see
  [cards](cards.md) "The lexicon"); a fresh database — and the Fly machine — has it empty. Nothing
  breaks: Swedish then simply falls back to CALL 1's answers for gender and verb forms.

- `RegisteredUserController@store` still validates against the legacy free-text
  `targetLanguage`/`nativeLanguage`/`code` fields (there's a hardcoded invite `code` = `delina`)
  rather than the `languages`/`language_user` model introduced later (see [multi-language](multi-language.md)).
  Registration does not yet set `native_language_id` / attach a target language via the pivot —
  a new user has to do that on `/profile/edit` before capturing works
  (`ProposalController@store` refuses with a 422 if they have no attached language).
- **History note, not a current bug**: `CardController@store` (an old AI-calling variant of card
  creation) and the jobs `CreateCardJob`/`CallAIJob` (referenced the dropped `question` column,
  unreachable) have been deleted outright rather than kept as dead code. The vocabulary-base
  redesign deleted the next layer of that history the same way, rather than leaving it dormant:
  `AjaxController@index` (the old synchronous capture; `AjaxController` is now just
  `saveLearning`), `AjaxController@setCaptureTarget` + `POST /capture-target` + the
  `capture_language_id`/`capture_wordbox_id` session keys (the save-destination picker),
  `CardController@learnAsPhrase` + `Card::createFromPhrase()` + `Card::suggestedPhrases()` +
  `<x-phrase-suggestions>` (the "learn it in a phrase instead" nudge), `Card::phraseHtml()` and
  `Card::resolveFocusWord()` (nothing left to bold), `GET /api/save-options`, `AI::test()`, and the
  scratch routes `POST /test`, `GET /test/createdGapFill` and the `dd()`-ing `POST /addWordAPI` in
  `web.php`. `CardController::save()` — the manual "/add" entry point, no AI involved —
  used to be *shadowed by a duplicate route registration* (a second, working, closure-based
  `/cards/new` handler was silently unreachable because Laravel matches the first-registered
  route) and its old body referenced an undefined property; both are fixed now, and `save()` is
  the one real handler. `CardController@show`/`@edit` and the update route used to have no
  ownership check at all; they now go through `CardPolicy` (see the Authorization note above).
