# Frase Project Guidelines

This document outlines the coding standards, patterns, and architectural decisions for the Frase project.

This is a language learning app that allows users to save words and phrases for later review based on various learning methods (flashcards). Is also gets AI generated context for the saved phrases and words to help user learn more effectively.

## Core Technologies
- **PHP**: 8.4
- **Laravel**: 12.x
- **Frontend**: Tailwind CSS 3, Blade Components, jQuery (for Ajax), Vite
- **Database**: SQLite (default)

## Application Architecture

### Routing
- Routes are defined in `routes/web.php`.
- A mix of **Controller-based routes** and **Route Closures** is used. 
- For complex logic or multi-step processes, prefer Controllers (e.g., `CardController`, `WordboxController`).
- Simple logic or direct view returns often use closures in `web.php`.

### Controllers & Logic
- Controllers often handle validation manually within methods using `$request->validate()` or Form Requests (e.g., `StoreCardRequest`).
- Some business logic is contained within models (e.g., `AI`, `Learning`).
- AI integration (OpenAI/LLM) is encapsulated in the `App\Models\AI` model.

### Models & Database
- Eloquent models are stored in `app/Models`.
- Relationships should be explicitly defined with return types.
- Mass assignment protection is typically disabled using `protected $guarded = [];`.
- Migrations follow standard Laravel naming conventions (`YYYY_MM_DD_HHMMSS_create_table_name.php`).

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
