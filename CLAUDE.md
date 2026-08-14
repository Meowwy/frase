# Frase Project Guidelines

Frase is a **language-learning app**: users save words/phrases for spaced-repetition review
(flashcards) and get **AI-generated context** for them to learn more effectively, across up to 5
target languages plus optionally their own native one.

**PHP 8.4, Laravel 12.x, Tailwind CSS 3 + Blade + jQuery (+ small Alpine.js), Vite, SQLite.**

## Documentation map

Full architecture, technical detail, and — most importantly — the **intent** behind each
feature (the "why", which the code alone won't tell you) live in `docs/`, one file per area.

**Read only the doc(s) relevant to the task in front of you** — don't load the whole set into
context for a small change. When you touch something documented, update its doc file in the same
change; these files are the project's only record of *why*, not just *what*.

| File | Covers |
|---|---|
| [docs/features-overview.md](docs/features-overview.md) | What a user can actually do — a plain-English feature catalog, not architecture |
| [docs/overview.md](docs/overview.md) | Routing/controller conventions, coding standards, dev-environment gotchas, known dead code paths — **read this first if you're new to the repo** |
| [docs/ai-integration.md](docs/ai-integration.md) | Every OpenAI call (`App\Models\AI`): model/params, every prompt-design rule and the failure it fixed, all card/chat generator methods |
| [docs/cards.md](docs/cards.md) | The `Card` model/schema, the capture flow, the card detail page, manual card linking, the `/cards` vocabulary list + filters |
| [docs/multi-language.md](docs/multi-language.md) | Target/native languages, the `language_user` pivot, native-language-vocabulary opt-in, save-destination picker, the shared `<x-wordbox-picker>` component |
| [docs/learning-flow.md](docs/learning-flow.md) | The `/setLearning` builder, the SRS scheduling algorithm, all flashcard learning modes (incl. Sentences-writing), the SRS Conversation mode |
| [docs/conversation-challenge.md](docs/conversation-challenge.md) | Conversation Challenge (free chat, text mode): setup, live per-message corrections, end recap |
| [docs/conversation-voice.md](docs/conversation-voice.md) | Conversation Challenge — voice variant (OpenAI Realtime API, WebRTC) |
| [docs/conversation-game.md](docs/conversation-game.md) | Conversation Challenge — game variant (AI-set tasks, 3 lives, 10 turns) |
| [docs/gap-fill.md](docs/gap-fill.md) | Per-wordbox gap-fill exercises (the one queued/async AI feature) |
| [docs/wordboxes-themes-tags.md](docs/wordboxes-themes-tags.md) | Wordboxes (active), Themes (legacy but live), Tags (dormant schema) |
| [docs/search-and-linking.md](docs/search-and-linking.md) | The `/search` box, card embeddings, and the paused automatic similarity-linking system |
| [docs/auth-and-users.md](docs/auth-and-users.md) | Registration/login, the `User` model, `/profile` |
| [docs/frontend-patterns.md](docs/frontend-patterns.md) | Styling conventions, Blade component catalogue, the jQuery/Alpine/Toastr JS stack |
| [docs/browser-extension.md](docs/browser-extension.md) | The Chrome extension's API contract (`routes/api.php`) — extension code itself lives outside this repo |

## Core guidelines

- Follow Laravel best practices and, above all, **stay consistent** with the surrounding code. If
  you find code that doesn't follow modern Laravel conventions, it's fine to suggest a fix — but
  don't rewrite it as a drive-by while doing something unrelated.
- Prefer the simplest solution that solves the actual problem; don't add abstraction or
  defensive handling for cases that can't occur here.
- **Update the relevant `docs/*.md` file whenever you change the app's architecture** — new
  table/endpoint/convention, or a rule a prompt depends on. See `docs/overview.md`'s own note on
  this for what "relevant" means in practice.
- Stick to the existing naming conventions and patterns (see `docs/overview.md`).
- You don't need to run `npm run build` during normal development — Vite's dev server hot-reloads.
  **Exception**: previewing via the `frase.test` Herd environment when the dev server is *not*
  running — `@vite` then falls back to `public/build`, and a stale build silently drops recently
  added Tailwind classes. Check for `public/hot` to know which mode you're in; if it's missing,
  either start `npm run dev` or run `npm run build` first.
