# Auth & User Profile

Registration/login, the `User` model, and the `/profile` pages. For the language/proficiency
side of the profile (which is most of what `/profile/edit` actually does), see
[multi-language](multi-language.md) — this doc covers the account-level pieces around it.

## Registration & session

Standard Laravel session auth, not Breeze/Jetstream scaffolding — hand-rolled controllers:

- `GET/POST /register` (`RegisteredUserController`) — validates `username`, `email` (unique),
  `password` (confirmed, min 6), and **three fields that predate the language system**:
  `targetLanguage`, `nativeLanguage` (both required, must differ), and a hardcoded invite `code`
  (must equal the literal string `'delina'` — this is a simple signup gate, not a real invite
  system). Creates the user with the **legacy free-text** `target_language`/`native_language`
  columns and `currency_amount: 100` (an unused early monetization concept — see
  [overview](overview.md)/[ai-integration](ai-integration.md) for other leftovers from that idea, like `CreateCardJob`
  decrementing it), and the `timezone` a hidden field picks up from the browser (see "Timezone"
  below). Does **not** set `native_language_id` or attach any language via the
  `language_user` pivot — a freshly registered user must visit `/profile/edit` and configure
  languages there before word capture will work (`ProposalController@store` refuses with a 422 if
  `currentSaveLanguage()` returns null). Logs the user in immediately after creating them.
- `GET/POST /login`, `DELETE /logout` (`SessionController`) — `Auth::attempt`, standard session
  regeneration on login, standard `Auth::logout` on the delete route.

Both controllers sit behind the route-group middleware split in `web.php`: `guest` middleware for
register/login, everything else behind `auth`.

## `User` model

`app/Models/User.php` — `HasApiTokens` (Sanctum, for the browser extension — see
[browser-extension](browser-extension.md)), `HasFactory`, `Notifiable`. Mass assignment open (`$guarded = []`);
`password`/`remember_token` hidden from serialization; `password` cast `hashed` (auto-hashing on
assignment, Laravel 11+ style, rather than manual `Hash::make` at every write site — though
`RegisteredUserController` still calls `Hash::make` explicitly, which is redundant but harmless
under the cast).

Key relations/helpers (documented in depth where they're actually used):

- `cards()`, `themes()`, `wordboxes()` — plain `hasMany`.
- `languages()`, `levelForLanguage()`, `activeLanguage()`, `nativeLanguage()`,
  `currentSaveLanguage()` — the whole multi-language save-destination system, see
  [multi-language](multi-language.md).

## `/profile` (`UserController@index`)

The account landing page: the user's themes, a short preview (up to 5) of their wordboxes
(`orderBy('position')`), a total wordbox count, and the flags of their languages
(`$user->languages()->get(['languages.id', 'flag'])`) for a quick-glance language row. It links
to `/profile/edit` and the wordbox ordering page. [Frammenti](frammenti.md) has no settings.

## `/profile/edit`

Covered in full in [multi-language](multi-language.md) (native-language picker, native-save checkbox, up-to-5
target-language picker with per-language CEFR level, hidden-language handling). The account-level
fields it also submits are `username`, `native_language_id` and `timezone`.

## Timezone

`users.timezone` (nullable IANA name) is what `User::today()` uses — the user's local date, falling
back to the app timezone (UTC). Every Frammenti date goes through it (due dates, sessions, the
streak), so "today" ends at the learner's midnight, not the server's: a streak
that breaks at the wrong hour feels like a bug. The browser detects it
(`Intl.DateTimeFormat().resolvedOptions().timeZone`): a hidden field at registration, and for a user
who still has none, a one-off `POST /profile/timezone` from the layout on any authenticated page,
which only ever fills an empty value. It is editable on `/profile/edit`.
