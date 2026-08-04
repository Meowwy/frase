@props(["card", "theme", "wordbox", "linkedCards"])
<x-html-layout>
    <div class="max-w-4xl mx-auto p-6 shadow-lg rounded-lg" id="cardShow" data-card-id="{{ $card->id }}">
        <a href="/cards" class="inline-flex items-center gap-1 text-white/70 hover:text-white transition-colors mb-6">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            <span>back</span>
        </a>

        <!-- Main Term Section -->
        <div class="mb-6 flex items-baseline justify-between gap-3">
            <div class="space-x-3">
                <span class="text-4xl font-bold">{{$card->phrase}}</span>
                <span class="text-xl italic">{{$card->translation}}</span>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                @if($card->language)
                    <span class="text-xl leading-none">{{$card->language->flag}}</span>
                @endif
                <a href="/cards/edit/{{$card->id}}" title="Edit card"
                   class="text-white/70 hover:text-white transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                </a>
            </div>
        </div>

        <!-- Usage-example boxes (the 3 short fragments) -->
        @php($examples = array_filter([$card->example_1, $card->example_2, $card->example_3]))
        @if(!empty($examples))
            <div class="mb-6 flex flex-wrap gap-3">
                @foreach($examples as $example)
                    <div class="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm text-white/90">
                        {{ $example }}
                    </div>
                @endforeach
            </div>
        @endif

        <!-- Definition Section -->
        <div class="mb-4">
            @if(!is_null($wordbox))
                <a href="{{ route('wordbox.show', $wordbox->id) }}"
                   class="capitalize text-sm mr-1 font-bold bg-orange-700 hover:bg-orange-600 text-white rounded-full px-3 py-1">{{$wordbox->name}}</a>
            @endif
            <span class="">{{$card->definition}}</span>
        </div>

        <!-- Example Sentence -->
        @if(!empty($card->example_sentence))
            <p class="mb-6 text-gray-400 font-medium">{!! $card->example_sentence !!}</p>
        @endif

        <!-- Linked cards -->
        <div class="mb-8" id="linkedCards">
            <h3 class="text-lg font-semibold mb-2">Linked cards</h3>

            <div class="relative max-w-md mb-3">
                <input type="text" id="linkSearch" placeholder="Search a term to link…" autocomplete="off"
                       class="w-full rounded-lg border border-white/10 bg-[#111] px-3 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-white/30">
                <div id="linkResults"
                     class="hidden absolute z-20 mt-1 w-full rounded-lg border border-white/10 bg-[#111] py-1 shadow-xl max-h-60 overflow-y-auto"></div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full max-w-2xl divide-y divide-gray-700 bg-white/5 text-xs">
                    <thead>
                    <tr>
                        <th class="px-4 py-2 text-left font-medium text-gray-300 uppercase tracking-wider">Term</th>
                        <th class="px-4 py-2 text-left font-medium text-gray-300 uppercase tracking-wider">Translation</th>
                        <th class="w-8 px-4 py-2"></th>
                    </tr>
                    </thead>
                    <tbody id="linkedCardsBody" class="divide-y divide-gray-700">
                    @include('cards._linked_rows', ['linkedCards' => $linkedCards])
                    </tbody>
                </table>
            </div>

            <!-- User note -->
            <div class="mt-4 max-w-md">
                <textarea id="noteInput" rows="4" placeholder="Add a note…"
                          class="w-full rounded-lg border border-white/10 bg-[#111] px-3 py-2 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-white/30">{{ $card->note }}</textarea>
                <div class="mt-2">
                    <button type="button" id="saveNote"
                            class="rounded-lg bg-white/10 hover:bg-white/20 px-4 py-2 text-sm font-medium text-white transition-colors">
                        Save note
                    </button>
                </div>
            </div>
        </div>

        <table class="mb-4 table-auto text-left text-white max-w-2xl divide-gray-700 bg-white/5">
            <thead class="text-white">
            <tr>
                <th class="px-4 py-3 text-xl">Last studied</th>
                <th class="px-4 py-3 text-xl">Level</th>
                <th class="px-4 py-3 text-xl">Next study scheduled on</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-700">
                <tr class="">
                    <td class="px-4 py-2 text-center">
                        @if(is_null($card->last_studied))
                            <span class="text-xl">Not studied</span>
                        @elseif($card->last_studied_days == 0)
                            <span class="text-xl">Today</span>
                        @elseif($card->last_studied_days == 1)
                            <span class="text-xl">{{$card->last_studied_days}} day ago</span>
                            <span class="text-sm"> ({{$card->last_studied}})</span>
                        @else
                            <span class="text-xl">{{$card->last_studied_days}} days ago</span>
                            <span class="text-sm"> ({{$card->last_studied}})</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-center text-xl">{{$card->level}}</td>
                    <td class="px-4 py-2 text-center text-xl">{{$card->next_study_at}}</td>
                </tr>
            </tbody>
        </table>
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
                    return '<button type="button" class="js-link-result block w-full text-left px-3 py-2 text-sm text-white hover:bg-white/10" data-id="' + c.id + '">' +
                        '<span class="font-medium">' + escapeHtml(c.phrase) + '</span>' +
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
                    const row =
                        '<tr class="group hover:bg-white/10 js-linked-row" data-linked-id="' + c.id + '">' +
                        '<td class="px-4 py-2 whitespace-nowrap font-medium text-white">' +
                        '<a href="/cards/' + c.id + '" class="hover:underline">' + escapeHtml(c.phrase) + '</a></td>' +
                        '<td class="px-4 py-2 text-gray-300">' + escapeHtml(c.translation) + '</td>' +
                        '<td class="w-8 px-4 py-2 text-right">' +
                        '<button type="button" class="js-unlink text-gray-500 hover:text-red-400 opacity-0 group-hover:opacity-100 transition-opacity" aria-label="Unlink term">' +
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
                            $body.html('<tr class="js-linked-empty"><td colspan="3" class="px-4 py-3 text-center text-gray-400">No linked cards yet.</td></tr>');
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
