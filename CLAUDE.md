# Frase Project Guidelines

This document outlines the coding standards, patterns, and architectural decisions for the Frase project.

This is a language learning app that allows users to save words and phrases for later review based on various learning methods (flashcards). Is also gets AI generated context for the saved phrases and words to help user learn more effectively.

## Core Technologies
- **PHP**: 8.4
- **Laravel**: 12.x
- **Frontend**: Tailwind CSS 3, Blade Components, jQuery (for Ajax), Vite
- **Database**: SQLite (default)

## Core guidelines
- All the code that you will write needs to follow the laravel best practices, standards and primarily be consistent. 
- Although if you find some code that not adheres modern laravel conventions, suggest to change it so it is correct.
- When creating solutions always look for the most efficient way to solve the problem, do not overcomplicate the code.
- Always document any changes did to the application architecture here in this AGENT.md file for future reference. This file is supposed to hold the current architecture of the application and also as a guideline for future contributions so they are consistent with the conventions.
- Stick to the current naming conventions and patterns.
- You don't have to run "npm run build" after implementing new features, because I use hot reload and can see changes in the dev environment.

## Application Architecture

### Routing
- Routes are defined in `routes/web.php`.
- A mix of **Controller-based routes** and **Route Closures** is used. 
- For complex logic or multi-step processes, prefer Controllers (e.g., `CardController`, `WordboxController`).
- Simple logic or direct view returns often use closures in `web.php`.

### Controllers & Logic
- Controllers often handle validation manually within methods using `$request->validate()` or Form Requests (e.g., `StoreCardRequest`).
- Some business logic is contained within models (e.g., `AI`, `Learning`).
### AI Integration
- AI integration (OpenAI/LLM) is encapsulated in the `App\Models\AI` model.
- **Model & params**: Chat generation uses the `gpt-5.4-nano` model (defined in the `AI::MODEL` constant) via the Chat Completions endpoint with `reasoning_effort` set to `low` (the `AI::REASONING_EFFORT` constant). Embeddings still use `text-embedding-3-small`. Reasoning models reject `temperature`, so it is never sent. All structured responses use strict `json_schema` response formats.
- **Prompt conventions**: Flashcard prompts keep the system message to the tutor's role/rules and the per-field instructions in the schema property descriptions. Two structural rules are enforced: the example sentence must contain the term wrapped in square brackets exactly once (`[term]`) so the learning blanking regex `/\[.*?\]/` works, and the generated question must read like a vocabulary test whose only correct answer is the term, without containing the term itself.
- **Gap-Fill Exercises**: Dynamic learning exercises are generated using `GenerateGapFillJob`. Exercises are stored in the `gap_fill_exercises` table and include a text with numbered placeholders `[n]` and a JSON mapping of answers.
- **Background Processing**: Time-consuming AI tasks use Laravel's queue system (`GenerateGapFillJob`). UI feedback for pending tasks is handled via AJAX polling (`gap-fill.status` route).

### Models & Database
- Eloquent models are stored in `app/Models`.
- Relationships should be explicitly defined with return types.
- Mass assignment protection is typically disabled using `protected $guarded = [];`.
- Migrations follow standard Laravel naming conventions (`YYYY_MM_DD_HHMMSS_create_table_name.php`).

### Multi-language Vocabulary
- The app supports vocabulary in multiple languages. The `languages` table is a static, seeded reference list (ISO 639-1 `code`, English `name`, `native_name`, `flag`) populated by `LanguageSeeder` (idempotent on `code`); add languages there.
- A user learns **up to 5 target languages** via the `language_user` pivot (`$user->languages()`), has one `native_language_id`, and one `active_language_id` (the durable default "save" language). These FKs supersede the legacy free-text `users.target_language` / `users.native_language` columns, which are kept temporarily for backfill and dropped later.
- `cards`, `wordboxes`, and `themes` each carry a `language_id`. A card belongs to exactly one language; "General vocabulary" = cards in a language not attached to any wordbox.
- **Save destination**: where a new word is saved (language + optional wordbox) is chosen in the right-side picker on the dashboard and persisted in the session (`capture_language_id`, `capture_wordbox_id`) via `POST /capture-target`. `User::currentSaveLanguage()` resolves session → `active_language_id` → first target language. `AjaxController@index` reads this to set the new card's `language_id`, scope the duplicate check and themes per-language, and (optionally) attach the matching wordbox.
- The save-destination picker controls **only** where words are saved; it does not reload or re-scope the page. Dashboard browse views and the learning/flashcard flow are intentionally still cross-language and slated for a later language-aware redesign — `language_id` is in place to support it.
- Queue jobs have no session/Auth, so they derive language from their own data (e.g. `GenerateGapFillJob` uses `$wordbox->language`).

## Frontend Patterns

### Styling
- **Tailwind CSS** is used exclusively for styling.
- Follow a "Dark Mode first" approach as the app uses a dark theme by default (`bg-black text-white`).
- Use utility classes directly in Blade files.

### Blade Components
- The project makes heavy use of anonymous Blade components located in `resources/views/components`.
- Layout: Use `<x-html-layout>` as the base wrapper.
- Forms: Use `<x-forms.*>` components (e.g., `<x-forms.input>`, `<x-forms.button>`) for consistency.
- UI Elements: Reuse components like `<x-panel>`, `<x-card>`, and `<x-section-heading>`.

### JavaScript & Ajax
- **jQuery** is used for DOM manipulation and Ajax requests.
- **Toastr** is used for notifications.
- Ajax endpoints often return JSON responses or simple status codes (e.g., `response(200)`).
- Scripts are often included directly in Blade files or managed via Vite in `resources/js/app.js`.

## Coding Standards

### PHP
- Use PHP 8+ features where appropriate (Constructor Property Promotion, Type Hints, etc.).
- Follow PSR-12 coding standards.
- Run `vendor/bin/pint` to ensure code formatting consistency.

### Naming Conventions
- Controllers: `PascalCaseController` (e.g., `CardController`).
- Models: `PascalCase` (e.g., `GapFillExercise`).
- Views: `kebab-case` or `snake_case` (e.g., `add.blade.php`, `html-layout.blade.php`).
- Components: `kebab-case` (e.g., `section-heading.blade.php`).

### Best Practices
- **Localization**: While the UI is in English, the app supports target/native language settings for users.
- **AI Integration**: Always handle AI failures gracefully (log errors, return nulls, notify user).
- **Security**: Always use `@csrf` in forms and ensure `@auth`/`@guest` checks are used in views for conditional rendering.
