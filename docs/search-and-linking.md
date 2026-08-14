# Search, Embeddings & Automatic Similarity Linking

Full-text-ish search over a user's cards, and the embedding-based "related terms" infrastructure
that exists in the schema but is currently switched off in favor of manual linking. For the
**manual** card-linking feature users actually see today, see [cards](cards.md) "Manual card linking" —
this doc covers the two `synonyms`/`related_terms` tables' original automatic purpose and how
embeddings still get generated.

## Search (`SeachController` — note the typo, it's the real class/file name)

- `GET /search` (`@index`) — validates `searchTerm` (min 2 chars), does a `LIKE %term%` match on
  `phrase` scoped to the current user, ordered so **prefix matches sort first**
  (`ORDER BY CASE WHEN phrase LIKE 'term%' THEN 0 ELSE 1 END`), limited to 15 results. Bracket
  markers in each result's `example_sentence` are turned into `<span class="font-bold">` before
  rendering (same bracket-highlighting pattern used on the card detail page — see
  [cards](cards.md), and note both **escape the sentence first** with `e()` before splicing in
  that `<span>`, since the view renders the result raw with `{!! !!}`).
- `GET /searchWordbox/{wbid}` (`@searchWordbox`) — a similar but separate search scoped for the
  wordbox edit page: returns matches (limit 10) either as JSON (AJAX request) for an inline
  results dropdown, or as part of a full `wordbox.edit` render otherwise.
- This is a plain `LIKE` query, not embedding-based — the embedding pipeline described below is
  unrelated to what powers the search box in the nav.

## Embeddings

Every card creation dispatches **`GenerateEmbeddingJob`** (queued), which calls
`AI::getEmbedding($card->phrase)` (`text-embedding-3-small`) and stores the result in
`cards.embedding` (nullable, `array` cast). `AI::cosineSimilarity($a, $b)` computes the dot
product between two embedding vectors (OpenAI's embeddings are pre-normalized, so a plain dot
product *is* cosine similarity — no separate magnitude division needed).

## Automatic linking — paused, kept for later

`GenerateEmbeddingJob` originally used that similarity score to **auto-populate** two tables:

- **`synonyms`** (`Synonym` model: `card_id`, `synonym_card_id`, nullable `similarity_score`) —
  written for a pair scoring **≥ 0.90**.
- **`related_terms`** (`RelatedTerm` model: `card_id`, `related_card_id`, `similarity_score`) —
  written for a pair scoring **≥ 0.75** (and below 0.90).

Both were written as **mirrored pairs** in each direction (`updateOrCreate` both ways), the same
convention manual linking uses today.

This automatic pass is **commented out** in `GenerateEmbeddingJob` — explicitly marked "paused/
work in progress" rather than removed. The reason: the `synonyms` table has since been
**repurposed** as the storage for the user-facing **manual** "Linked cards" feature (see
[cards](cards.md)), and `similarity_score` was made nullable specifically so a manual link (which carries
no score) can share the same table/column as a would-be automatic one. Re-enabling the commented
block would start **populating the same "Linked cards" UI** the user sees on the card detail
page, mixed in with their manual links — so if this is ever turned back on, it needs a way to
distinguish (or the product decision needs to be "yes, merge them") before going live, since right
now every row in `synonyms` is user-intentional.

`related_terms`, unrelated to the manual-linking feature, is exposed read-only via
`GET /cards/{card}/synonyms` (a closure in `web.php`, despite the URL — it actually returns both
`synonyms` and `related_terms` together as JSON, gated by
`abort_unless(Auth::user()->can('view', $card), 403)` — see [overview](overview.md)
"Authorization"), but with no automatic writer running, it will stay empty until the job is
re-enabled.
