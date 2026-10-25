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
- `<x-html-layout>` — the base page wrapper: doctype, the navs (see "Responsive / mobile" below
  for the mobile one; the desktop nav is sticky at the top, so anything else sticky must sit below
  it — `/base`'s side panel uses `lg:top-24`), CDN includes (jQuery, Toastr, Google Fonts),
  `@vite(...)` for `app.css`/`app.js`, and the `{{ $slot }}` for page content. `:tab-bar="false"`
  hides the mobile tab bar on a full-screen task page.
- `<x-forms.*>` — `input`, `input-search`, `textarea`, `select`, `checkbox`, `button`,
  `button-small`, `button-confirm`, `button-delete`, `label`, `field`, `error`, `divider`, `form`,
  `option` — the whole form-control vocabulary. Reach for these before writing a raw `<input>`.
  `<x-forms.button disabled="true">` renders dimmed with a not-allowed cursor.
- Reusable UI: `<x-panel>`, `<x-card>` / `<x-card-small>` / `<x-card-text>` / `<x-card-wordbox>`
  (different card-rendering contexts — list row, small preview, plain text, wordbox-page tile),
  `<x-section-heading>`, `<x-page-heading>`, `<x-modal>`, `<x-tag>`, `<x-theme-card>`,
  `<x-number-display>`, `<x-learning-due-card>`, `<x-kbd>` (a keyboard-shortcut badge, styled
  translucent-white to sit inside a coloured button; it hides itself below lg).
- `<x-wordbox-picker>` is the one component that owns significant client-side behaviour of its
  own rather than just rendering markup — see [multi-language](multi-language.md) for its full contract
  (`window.WordboxPicker.current()` + the `wordboxpicker:change` event). It's the model to follow
  if another shared, stateful picker is ever needed: keep the state and DOM-manipulation JS
  inside the component's own `<script>` block, and communicate outward only through a small,
  documented JS/event surface, never by having the consuming page reach into the component's DOM.

## Responsive / mobile

On a phone Frase should feel like an app, not a shrunken desktop page: capturing, reviewing and
learning words one-handed. **The desktop layout (lg, 1024px, and up) is the reference and stays
exactly as designed**; everything below lg is added on top of it.

- **Breakpoints**: Tailwind's defaults. New layout is written mobile-first (base classes for the
  phone, `sm:`/`md:`/`lg:` above). Changes to markup that was designed for desktop use `max-lg:`
  instead, so the desktop's computed styles provably don't change — the touch-target bumps on the
  shared buttons (`max-lg:min-h-11`) and most page-level overrides are written that way.
- **Never pair `hidden` with a responsive display class** (`hidden lg:flex`): `app.css` defines its
  own `.hidden` after Tailwind's utilities, so it beats every `lg:` display class and the element
  stays hidden on desktop too. Hide below lg with `max-lg:hidden`, above it with `lg:hidden`. A
  class that JS toggles (`hidden`, `max-lg:hidden`) is safe next to any other class.
- **Mobile chrome** (`html-layout`, `lg:hidden`): a fixed top bar (logo, an always-open search box,
  a menu button) and a fixed bottom **tab bar** with the five main destinations — Home (capture),
  Staging, Cards, Base, Learn. The menu drops down under the top bar with the rest of the desktop
  nav: Frammenti, Conversation, account settings, log out. Five tabs is the platform norm, and
  keeping the overflow in the top bar's menu means it's reachable on pages without a tab bar too.
  Both navs render a `.js-staged-count` badge; every script that updates the count addresses the
  class, so all badges update. The wrapper pads its top by the bar's height and the body its bottom
  by the tab bar's.
- **Full-screen task pages** (`<x-html-layout :tab-bar="false">`): the flashcard session,
  Frammenti and every chat. As in a native app's pushed screen, the tab bar steps aside for the
  page's own bottom bar; the top bar stays, so there's always a way out. Chat screens fill the
  screen with `min-h-app-screen` (a `tailwind.config.js` height: the dynamic viewport minus the
  top bar, main's margin and the body padding) so the composer starts at the bottom edge, and the
  composer is `sticky bottom-0` once the thread is longer than the screen. The flashcard session's
  bottom panel is `fixed`: its counters stay in their desktop place in the DOM, fixed on top of the
  button bar at an offset equal to the bar's height. Use **`dvh`, never `vh`**, for anything sized to the screen (`vh` ignores the
  browser's collapsing toolbars).
- **Safe areas**: the viewport meta has `viewport-fit=cover` (and allows pinch-zoom), so every fixed
  or sticky bar pads itself with `env(safe-area-inset-*)` — the top bar the top inset, bottom bars
  the bottom one (`pb-[max(0.75rem,env(safe-area-inset-bottom))]`), the page wrapper the side
  ones. Below lg, `app.css` sets `scroll-padding` on `html`, so `scrollIntoView()` never lands
  under a fixed bar.
- **Touch targets** at least 44px (`min-h-11`, or `h-11 w-11` for an icon button). A small inline
  control that can't grow without moving its neighbours — a chip's ✕ — gets padding with an equal
  negative margin (`max-lg:-m-3 max-lg:p-3`), which grows the hit area and nothing else. Text in an
  input is at least **16px** (`text-base`) below lg, or iOS zooms in on focus. A term-entry field
  sets `autocapitalize="none"`/`autocorrect="off"`, so the phone keyboard doesn't rewrite a word
  in the language being learnt, and an `enterkeyhint` where Enter does something.
- **No hover on touch**: controls revealed on `group-hover` (row checkboxes, the row 3-dot menu,
  unlink X's) are always shown below lg (`max-lg:opacity-100`), and a hover tooltip is replaced by
  the same text written inline. Keyboard-shortcut hints (`<x-kbd>`) are hidden below lg.
- **Tables become stacked lists** below lg, on the same markup rather than a second template, so
  every JS selector, data attribute and AJAX row swap keeps working: `table`/`tbody` go `block`,
  each `tr` becomes a `flex flex-wrap` (or small `grid`) item, `thead` is hidden or keeps only its
  search inputs, and absolutely positioned cells put the checkbox in a left gutter (the whole
  gutter is its `<label>`) and the row menu in a right one. A row built in JS (`cards/show`'s
  linked rows, `/profile/edit`'s language rows) carries the same classes as its Blade twin, and
  so does an `@empty` row (`max-lg:block` on the `tr` and `td`).
- **Side panels and filters**: a side column that would land under a long list becomes a
  height-capped (≤55dvh) bottom sheet that scrolls inside and opens only on a deliberate tap
  (tapping the same row again closes it), and multi-select actions go in a one-row bar above the
  tab bar that never grows; only one of the two is on screen at a time, and while rows are
  checked a row tap toggles its check (`/base`); a filter
  bar folds into one control naming the current selection (`/cards`'s picker). `<x-modal>` is a
  bottom sheet below lg.

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
  action, rather than full-page flash messages, on most interactive pages. Its script must load
  **after** jQuery: it binds to `window.jQuery` as it loads, and loaded first, every `toastr.*`
  call throws — taking down whatever handler made it.
- Ajax endpoints return either a redirect (non-AJAX form submissions), a plain status code
  (`response(200)`), or a JSON body — check `$request->ajax()`/`$request->expectsJson()` in a
  controller before assuming which one a given endpoint returns; several endpoints in this app
  (`CardController@index`, `ProposalController@list`) deliberately support both a normal-page and an
  AJAX response from the same action.
- Scripts are either written inline in the relevant Blade file (the common pattern for
  page-specific behaviour — chat views, the flashcard session, the wordbox picker component) or
  go through Vite via `resources/js/app.js` (currently just the Alpine bootstrap + image glob).
  There's no per-page bundled JS file convention — inline `<script>` in the Blade view is normal
  here, not a code smell to clean up.
