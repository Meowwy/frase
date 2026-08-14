# Frontend Patterns

Styling, Blade component conventions, and the JS stack. For a specific page's own JS behaviour
(chat views, the flashcard session, the wordbox picker), see that feature's own doc — this file
covers the patterns shared across all of them.

## Styling

- **Tailwind CSS**, utility classes directly in Blade files — no component-scoped CSS files.
- **Dark mode first**: the whole app is dark by default (`bg-black text-white` on `<body>` in
  `html-layout.blade.php`), not a toggle-based light/dark theme. Design new UI against a dark
  background as the only background.
- Flag emoji (`languages.flag`) render via a locally bundled, base64-inlined webfont
  (`resources/css/flags.css`, imported first in `app.css`) rather than relying on the OS's
  flag-emoji support or an external CDN fetch — Windows in particular has no native flag-emoji
  glyphs, and an earlier Twemoji-via-CDN approach caused a visible "GB"-text flash before the SVG
  loaded. The font holds *only* flag glyphs, so regular Latin text in the same font stack falls
  through to Lato per-glyph — no JS/MutationObserver needed to keep flags rendering correctly
  when new DOM is inserted client-side (e.g. picker tags built by jQuery).

## Blade components

- Heavy use of **anonymous** Blade components in `resources/views/components` (and
  `components/forms` for the form-input family) — no class-backed components in this app.
- `<x-html-layout>` — the base page wrapper: doctype, nav, CDN includes (jQuery, Toastr, Google
  Fonts), `@vite(...)` for `app.css`/`app.js`, and the `{{ $slot }}` for page content.
- `<x-forms.*>` — `input`, `input-search`, `textarea`, `select`, `checkbox`, `button`,
  `button-small`, `button-confirm`, `button-delete`, `label`, `field`, `error`, `divider`, `form`,
  `option` — the whole form-control vocabulary. Reach for these before writing a raw `<input>`.
- Reusable UI: `<x-panel>`, `<x-card>` / `<x-card-small>` / `<x-card-text>` / `<x-card-wordbox>`
  (different card-rendering contexts — list row, small preview, plain text, wordbox-page tile),
  `<x-section-heading>`, `<x-page-heading>`, `<x-modal>`, `<x-tag>`, `<x-theme-card>`,
  `<x-number-display>`, `<x-learning-due-card>`.
- `<x-wordbox-picker>` is the one component that owns significant client-side behaviour of its
  own rather than just rendering markup — see [multi-language](multi-language.md) for its full contract
  (`window.WordboxPicker.current()` + the `wordboxpicker:change` event). It's the model to follow
  if another shared, stateful picker is ever needed: keep the state and DOM-manipulation JS
  inside the component's own `<script>` block, and communicate outward only through a small,
  documented JS/event surface, never by having the consuming page reach into the component's DOM.

## JavaScript & Ajax

Three JS layers coexist, each with a distinct job — don't reach for the wrong one:

- **jQuery** (loaded globally from a CDN in `html-layout.blade.php`, *not* bundled via npm) is the
  default for DOM manipulation and Ajax (`$.post`/`$.ajax`) across the app — the flashcard
  session, every chat feature's per-turn request, the vocabulary list's live search/filter, the
  wordbox picker. This is the pattern to reach for first for a new interactive page.
- **Alpine.js** (`window.Alpine`, started in `resources/js/app.js`, bundled via Vite) is used
  sparingly for small self-contained interactions — e.g. a confirmation modal — where `x-data`
  scoping is a better fit than manual jQuery state. It is not the default; most pages use none of
  it.
- **Axios** (`resources/js/bootstrap.js`) is wired up with `X-Requested-With: XMLHttpRequest` on
  every request, but isn't actually used for requests anywhere observed in the app's own JS — it
  exists as Laravel's default scaffolding. jQuery's `$.post`/`$.ajax` is what every feature
  actually calls.
- **Toastr** (CDN) is the client-side notification library — success/error toasts after an Ajax
  action, rather than full-page flash messages, on most interactive pages.
- Ajax endpoints return either a redirect (non-AJAX form submissions), a plain status code
  (`response(200)`), or a JSON body — check `$request->ajax()`/`$request->expectsJson()` in a
  controller before assuming which one a given endpoint returns; several endpoints in this app
  (`CardController@index`, `AjaxController@index`) deliberately support both a normal-page and an
  AJAX response from the same action.
- Scripts are either written inline in the relevant Blade file (the common pattern for
  page-specific behaviour — chat views, the flashcard session, the wordbox picker component) or
  go through Vite via `resources/js/app.js` (currently just the Alpine bootstrap + image glob).
  There's no per-page bundled JS file convention — inline `<script>` in the Blade view is normal
  here, not a code smell to clean up.
