# Browser Extension

A separate Chrome extension (MV3) that lets a user capture a word into Frase from any webpage,
without opening the site. **Lives outside this repo** — on this machine it's at
`D:\3 Resources\Frase_extension` (`manifest.json`, `frase_popup.html`, `popup.js`,
`frase_logo.png`). Only the Laravel side of the integration (`routes/api.php`) lives here; treat
this doc as the contract the extension code depends on, since it can't be discovered by reading
this repo alone.

## Why a separate API surface

The main app (`routes/web.php`) is entirely **session**-based, which a browser-extension popup
can't rely on the way a same-origin page can. `routes/api.php` is a small, **Sanctum
token**-authenticated, stateless surface specifically for the extension — the extension has no
session and must pass everything it needs (including `language_id`/`wordbox_id`) explicitly on
every request, unlike the website which can fall back to `session('capture_language_id')` (see
[multi-language](multi-language.md)).

## Endpoints

- **`POST /api/extension/login`** *(unauthenticated)* — `{email, password}` →
  `Auth::attempt`, then `Auth::user()->createToken('browser-extension')->plainTextToken`. The
  extension stores this token in `chrome.storage.local` and sends it as a Bearer token on every
  subsequent call. A 401 response anywhere below should force the extension back to this login
  step.
- **`POST /api/addWordAPI`** *(Sanctum, `auth:sanctum` group)* → routes straight to
  **`AjaxController@index`** — the exact same capture endpoint the website's own capture form
  uses (`POST /captureWordAjax` on the web side). No extension-specific controller code exists;
  the shared endpoint already validates/honors `language_id`/`wordbox_id` in the request body and
  returns JSON (it branches on `$request->expectsJson()`), so nothing extra was needed to support
  the extension. See [cards](cards.md) "Capture flow" for exactly what this endpoint does.
- **`GET /api/save-options`** *(Sanctum)* — returns the flat list of save-destination options for
  the extension's own dropdown (it can't reuse the web `<x-wordbox-picker>` component, being a
  separate popup UI): one entry per language × (general vocabulary, then each of that language's
  wordboxes A–Z), languages ordered alphabetically. Each entry:
  `{value, label, language_id, wordbox_id}` where `value` is `"<language_id>:<wordbox_id>"`
  (`"<id>:"` with nothing after the colon = general vocabulary, no wordbox) and `label` is plain
  text like `"English - general"` / `"English - Travel"` — **no flag emoji**, deliberately, since
  the flag webfont trick described in [frontend-patterns](frontend-patterns.md) isn't bundled into the extension
  popup, and Windows renders raw flag emoji as literal two-letter codes ("GB") without it. Also
  returns `selected`: the `value` corresponding to the user's `currentSaveLanguage()`, so the
  extension can preselect a sensible default without an extra round trip.

## Extension-side contract (for reference, not owned by this repo)

- `popup.js`'s `loadSaveOptions()` fetches `/save-options` when the popup's main view is shown and
  populates the dropdown, preselecting `selected`.
- On capture, it splits the chosen dropdown `value` back into `language_id` + `wordbox_id` and
  includes both in the `addWordAPI` request body.
- A 401 from any call forces the popup back to the login view; a failed `/save-options` fetch
  leaves the dropdown empty rather than blocking capture — the server still falls back to the
  user's default save language via `currentSaveLanguage()` in that case.

If you change the shape of `/save-options` or `/addWordAPI`'s expected body, the extension code
needs a matching update — there's no shared type/schema between the two repos to catch a mismatch
automatically.
