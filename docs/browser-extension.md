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
token**-authenticated, stateless surface specifically for the extension.

Since the vocabulary-base redesign it needs nothing else: a capture carries only the term and an
optional context, because the language is detected by CALL 1 and there is no save destination to
choose. That is what let `/save-options` go.

## Endpoints

- **`POST /api/extension/login`** *(unauthenticated)* — `{email, password}` →
  `Auth::attempt`, then `Auth::user()->createToken('browser-extension')->plainTextToken`. The
  extension stores this token in `chrome.storage.local` and sends it as a Bearer token on every
  subsequent call. A 401 response anywhere below should force the extension back to this login
  step.
- **`POST /api/addWordAPI`** *(Sanctum, `auth:sanctum` group; route name `captureApi`)* → routes
  straight to **`ProposalController@store`** — the exact same capture endpoint the website's own
  capture form uses (`POST /capture` on the web side). No extension-specific controller code
  exists; the endpoint always answers JSON, and the only thing it branches on is `$request->routeIs('captureApi')`,
  which it records as the proposal's `source`. Body: `{capturedWord, context?}` — and nothing else.
  Answers `{proposal_id, staged_count, message}`.
  **It no longer waits on the AI at all**: it writes a `proposals` row and returns, and CALL 1 runs
  on the queue afterwards. The popup used to sit through two sequential model calls; now the term
  appears in the website's staging feed, where the user approves it. See [cards](cards.md)
  "Staging".

## Extension-side contract (for reference, not owned by this repo)

- The popup sends `{capturedWord, context?}` with the Bearer token. A 401 from any call forces it
  back to the login view.
- **`GET /api/save-options` is gone**, along with the save-destination dropdown it fed. Capture has
  no destination to choose any more, so the popup should drop `loadSaveOptions()` and stop sending
  `language_id`/`wordbox_id` (they are ignored if sent). The old code degrades safely in the
  meantime: it already left the dropdown empty on a failed fetch rather than blocking capture.
- The success message is worth surfacing verbatim — it says the term is *in staging*, not that a
  card exists, which is the one thing that changed for the user.

If you change the shape of `/addWordAPI`'s expected body, the extension code needs a matching
update — there's no shared type/schema between the two repos to catch a mismatch automatically.
