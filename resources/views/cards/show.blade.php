@props(["card", "theme", "linkedCards", "previousCard", "nextCard"])
<x-html-layout>
    <div class="max-w-4xl mx-auto p-6 shadow-lg rounded-lg max-lg:px-0 max-lg:py-2" id="cardShow" data-card-id="{{ $card->id }}">
        {{-- Back on the left, the prev/next walk through this language's cards on the
             right — same order /cards lists them in. A missing neighbour stays rendered
             but dimmed, so the row doesn't shift as the learner walks the list. --}}
        {{-- Below lg the labels shorten to "previous" / "next" and every link is a 44px target. --}}
        <div class="mb-6 flex items-center justify-between gap-3 max-lg:mb-4">
            <a href="/cards" class="inline-flex items-center gap-1 text-white/70 hover:text-white transition-colors max-lg:min-h-11">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                <span>back</span>
            </a>

            <div class="flex items-center gap-5 max-lg:gap-3">
                @if($previousCard)
                    <a href="/cards/{{ $previousCard->id }}" class="inline-flex items-center gap-1 text-white/70 hover:text-white transition-colors max-lg:min-h-11">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                        <span class="max-lg:hidden">previous card</span><span class="lg:hidden">previous</span>
                    </a>
                @else
                    <span class="inline-flex items-center gap-1 text-white/25 cursor-default max-lg:min-h-11">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                        <span class="max-lg:hidden">previous card</span><span class="lg:hidden">previous</span>
                    </span>
                @endif

                @if($nextCard)
                    <a href="/cards/{{ $nextCard->id }}" class="inline-flex items-center gap-1 text-white/70 hover:text-white transition-colors max-lg:min-h-11">
                        <span class="max-lg:hidden">next card</span><span class="lg:hidden">next</span>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                @else
                    <span class="inline-flex items-center gap-1 text-white/25 cursor-default max-lg:min-h-11">
                        <span class="max-lg:hidden">next card</span><span class="lg:hidden">next</span>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </span>
                @endif
            </div>
        </div>

        <!-- Main Term Section -->
        <div class="mb-6 flex items-baseline justify-between gap-3">
            {{-- A whole-sentence Term is far longer than a single word, so the heading
                 wraps instead of overflowing. --}}
            <div class="min-w-0 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                {{-- font-medium, not font-bold: there is no focus word left to emphasize
                     against, so the Term itself is simply what's shown. --}}
                <span class="text-3xl sm:text-4xl font-medium break-words">{{ $card->term }}</span>
                <span class="ml-2 text-xl italic">{{$card->translation}}</span>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                @if($card->language)
                    <span class="text-xl leading-none">{{$card->language->flag}}</span>
                @endif
                <a href="/cards/edit/{{$card->id}}" title="Edit card"
                   class="text-white/70 hover:text-white transition-colors max-lg:-mr-2.5 max-lg:inline-flex max-lg:p-2.5">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                </a>
            </div>
        </div>

        <!-- Which of the Term's words are in the vocabulary base, and its fixed expressions.
             Not a review list: neither base carries a schedule, only coverage. -->
        @if($card->baseWords->isNotEmpty() || $card->fixedExpressions->isNotEmpty())
            <div class="mb-6 flex flex-wrap items-center gap-2">
                <span class="text-xs uppercase tracking-wider text-white/40">base words</span>
                @foreach($card->baseWords as $baseWord)
                    <a href="{{ route('base', ['language_id' => $card->language_id, 'selected' => 'word:'.$baseWord->id]) }}"
                       class="group relative rounded-lg border border-white/10 bg-white/5 px-3 py-1 text-sm hover:bg-white/10 transition-colors max-lg:py-2">
                        {{ $baseWord->displayForm() }}
                        <span class="ml-1 text-xs text-white/40">{{ $baseWord->part_of_speech }}</span>
                        @if($baseWord->translation)
                            {{-- No hover on touch: below lg the translation is written into the chip instead. --}}
                            <span class="ml-1 text-xs text-white/60 lg:hidden">· {{ $baseWord->translation }}</span>
                            <span role="tooltip"
                                  class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 -translate-x-1/2 whitespace-nowrap rounded-md bg-gray-700 px-3 py-1 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity duration-150 group-hover:opacity-100 max-lg:hidden">
                                {{ $baseWord->translation }}
                                <span class="absolute left-1/2 top-full -translate-x-1/2 border-[5px] border-transparent border-t-gray-700"></span>
                            </span>
                        @endif
                    </a>
                @endforeach
                @foreach($card->fixedExpressions as $expression)
                    <a href="{{ route('base', ['language_id' => $card->language_id, 'selected' => 'expression:'.$expression->id]) }}"
                       class="rounded-lg border border-blue-500/30 bg-blue-500/10 px-3 py-1 text-sm hover:bg-blue-500/20 transition-colors max-lg:py-2">
                        {{ $expression->form }}
                        <span class="ml-1 text-xs text-white/40">expression</span>
                    </a>
                @endforeach
            </div>
        @endif

        <!-- Definition Section -->
        <div class="mb-6">
            <span class="">{{$card->definition}}</span>
        </div>

        <!-- Linked cards -->
        <div class="mb-8" id="linkedCards">
            <h3 class="text-lg font-semibold mb-2">Linked cards</h3>

            <div class="relative max-w-md mb-3">
                <input type="text" id="linkSearch" placeholder="Search a term to link…" autocomplete="off"
                       class="w-full rounded-lg border border-white/10 bg-[#111] px-3 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-white/30 max-lg:py-2.5 max-lg:text-base">
                <div id="linkResults"
                     class="hidden absolute z-20 mt-1 w-full rounded-lg border border-white/10 bg-[#111] py-1 shadow-xl max-h-60 overflow-y-auto"></div>
            </div>

            {{-- Below lg the table stacks into a list (see cards._linked_rows). --}}
            <div class="overflow-x-auto">
                <table class="min-w-full max-w-2xl divide-y divide-gray-700 bg-white/5 text-xs max-lg:block max-lg:rounded-lg max-lg:text-sm">
                    <thead class="max-lg:hidden">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium text-gray-300 uppercase tracking-wider">Term</th>
                        <th class="px-4 py-2 text-left font-medium text-gray-300 uppercase tracking-wider">Translation</th>
                        <th class="w-8 px-4 py-2"></th>
                    </tr>
                    </thead>
                    <tbody id="linkedCardsBody" class="divide-y divide-gray-700 max-lg:block">
                    @include('cards._linked_rows', ['linkedCards' => $linkedCards])
                    </tbody>
                </table>
            </div>

            <!-- User note -->
            <div class="mt-4 max-w-md">
                <textarea id="noteInput" rows="4" placeholder="Add a note…"
                          class="w-full rounded-lg border border-white/10 bg-[#111] px-3 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-white/30 max-lg:text-base">{{ $card->note }}</textarea>
                <div class="mt-2">
                    <button type="button" id="saveNote"
                            class="rounded-lg bg-white/10 hover:bg-white/20 px-4 py-2 text-sm font-medium text-white transition-colors max-lg:min-h-11 max-lg:w-full">
                        Save note
                    </button>
                </div>
            </div>
        </div>

        @if($card->level >= 8)
            <div class="mb-4 max-w-2xl bg-white/5 px-4 py-3 text-xl text-white max-lg:w-full max-lg:text-base">
                Learned — this card has reached level {{$card->level}} and is no longer scheduled for review.
            </div>
        @else
        {{-- Below lg: full width, smaller type, so the three columns fit a phone. --}}
        <table class="mb-4 table-auto text-left text-white max-w-2xl divide-gray-700 bg-white/5 max-lg:w-full">
            <thead class="text-white">
            <tr>
                <th class="px-4 py-3 text-xl max-lg:px-2 max-lg:text-sm">Last studied</th>
                <th class="px-4 py-3 text-xl max-lg:px-2 max-lg:text-sm">Level</th>
                <th class="px-4 py-3 text-xl max-lg:px-2 max-lg:text-sm">Next study scheduled on</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-700">
                <tr class="">
                    <td class="px-4 py-2 text-center max-lg:px-2">
                        @if(is_null($card->last_studied))
                            <span class="text-xl max-lg:text-base">Not studied</span>
                        @elseif($card->last_studied_days == 0)
                            <span class="text-xl max-lg:text-base">Today</span>
                        @elseif($card->last_studied_days == 1)
                            <span class="text-xl max-lg:text-base">{{$card->last_studied_days}} day ago</span>
                            <span class="text-sm max-lg:block max-lg:text-xs"> ({{$card->last_studied}})</span>
                        @else
                            <span class="text-xl max-lg:text-base">{{$card->last_studied_days}} days ago</span>
                            <span class="text-sm max-lg:block max-lg:text-xs"> ({{$card->last_studied}})</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-center text-xl max-lg:px-2 max-lg:text-base">{{$card->level}}</td>
                    <td class="px-4 py-2 text-center text-xl max-lg:px-2 max-lg:text-base">{{$card->next_study_at}}</td>
                </tr>
            </tbody>
        </table>
        @endif
    </div>

    <script>
        // Manual card linking on the detail page: search same-language cards, click a
        // result to link (bidirectional on the backend), or unlink from the table.
        $(document).ready(function () {
            const cardId = $('#cardShow').data('card-id');
            const csrf = '{{ csrf_token() }}';
            let debounce;

            const $search = $('#linkSearch');
            const $results = $('#linkResults');
            const $body = $('#linkedCardsBody');

            function escapeHtml(s) {
                return $('<div>').text(s == null ? '' : s).html();
            }

            function hideResults() {
                $results.addClass('hidden').empty();
            }

            function renderResults(cards) {
                if (!cards.length) {
                    $results.html('<div class="px-3 py-2 text-xs text-gray-400">No matches</div>').removeClass('hidden');
                    return;
                }
                const html = cards.map(function (c) {
                    return '<button type="button" class="js-link-result block w-full text-left px-3 py-2 text-sm text-white hover:bg-white/10 max-lg:py-3 max-lg:text-base" data-id="' + c.id + '">' +
                        '<span class="font-medium">' + escapeHtml(c.term) + '</span>' +
                        (c.translation ? ' <span class="text-gray-400">' + escapeHtml(c.translation) + '</span>' : '') +
                        '</button>';
                }).join('');
                $results.html(html).removeClass('hidden');
            }

            $search.on('input', function () {
                const q = $(this).val().trim();
                clearTimeout(debounce);
                if (q === '') { hideResults(); return; }
                debounce = setTimeout(function () {
                    $.get('/cards/' + cardId + '/link-search', { q: q }, function (data) {
                        renderResults(data.results || []);
                    });
                }, 250);
            });

            // Link a selected card, then add its row to the table.
            $results.on('click', '.js-link-result', function () {
                const otherId = $(this).data('id');
                $.post('/cards/' + cardId + '/links', { card_id: otherId, _token: csrf }, function (data) {
                    const c = data.card;
                    $body.find('.js-linked-empty').remove();
                    // Same markup (and mobile stacking) as cards._linked_rows.
                    const row =
                        '<tr class="group hover:bg-white/10 js-linked-row max-lg:grid max-lg:grid-cols-[1fr_auto] max-lg:items-center max-lg:px-4 max-lg:py-2" data-linked-id="' + c.id + '">' +
                        '<td class="px-4 py-2 whitespace-nowrap font-medium text-white max-lg:col-start-1 max-lg:p-0 max-lg:whitespace-normal">' +
                        '<a href="/cards/' + c.id + '" class="hover:underline">' + escapeHtml(c.term) + '</a></td>' +
                        '<td class="px-4 py-2 text-gray-300 max-lg:col-start-1 max-lg:p-0">' + escapeHtml(c.translation) + '</td>' +
                        '<td class="w-8 px-4 py-2 text-right max-lg:col-start-2 max-lg:row-span-2 max-lg:row-start-1 max-lg:w-auto max-lg:p-0">' +
                        '<button type="button" class="js-unlink text-gray-500 hover:text-red-400 opacity-0 group-hover:opacity-100 transition-opacity max-lg:flex max-lg:h-11 max-lg:w-11 max-lg:items-center max-lg:justify-center max-lg:opacity-100" aria-label="Unlink term">' +
                        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>' +
                        '</button></td></tr>';
                    $body.append(row);
                    $search.val('');
                    hideResults();
                }).fail(function (xhr) {
                    const msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not link that card.';
                    if (window.toastr) { toastr.error(msg); }
                });
            });

            // Unlink from the table.
            $body.on('click', '.js-unlink', function () {
                const $row = $(this).closest('.js-linked-row');
                const otherId = $row.data('linked-id');
                $.ajax({
                    url: '/cards/' + cardId + '/links/' + otherId,
                    type: 'DELETE',
                    data: { _token: csrf },
                    success: function () {
                        $row.remove();
                        if ($body.find('.js-linked-row').length === 0) {
                            $body.html('<tr class="js-linked-empty max-lg:block"><td colspan="3" class="px-4 py-3 text-center text-gray-400 max-lg:block">No linked cards yet.</td></tr>');
                        }
                    }
                });
            });

            // Dismiss the results dropdown when clicking away.
            $(document).on('click', function (e) {
                if (!$(e.target).closest('#linkedCards .relative').length) { hideResults(); }
            });

            // Save the user note.
            $('#saveNote').on('click', function () {
                const $btn = $(this);
                $btn.prop('disabled', true);
                $.post('/cards/' + cardId + '/note', { note: $('#noteInput').val(), _token: csrf }, function () {
                    if (window.toastr) { toastr.success('Note saved.'); }
                }).fail(function () {
                    if (window.toastr) { toastr.error('Could not save the note.'); }
                }).always(function () {
                    $btn.prop('disabled', false);
                });
            });
        });
    </script>
</x-html-layout>
