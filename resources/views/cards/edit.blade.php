<x-html-layout>
    <x-page-heading>{{$card->term}}</x-page-heading>

    <x-forms.form method="post" action="/cards/{{$card->id}}">
        <input hidden value="{{$card->id}}" name="id"/>
        <x-forms.input value="{{$card->term}}" label="Term" name="term"/>
        <x-forms.input value="{{$card->translation}}" label="Translation" name="translation"/>
        <x-forms.textarea label="Note" name="note">{{$card->note}}</x-forms.textarea>
        <x-forms.divider></x-forms.divider>
        <div class="flex justify-between">
            <x-forms.button class="max-lg:w-full">Save</x-forms.button>
        </div>
    </x-forms.form>

    {{-- The card's words (docs/cards.md "Editing a card's words"): strike one as in staging
         (the link goes and the word becomes ignored), or link another word of the base. Each
         change is saved at once and reloads the page. --}}
    <section class="my-8">
        <h2 class="mb-3 text-xs uppercase tracking-wider text-white/40">Words</h2>
        <div class="flex flex-wrap gap-2">
            @foreach($card->baseWords as $baseWord)
                @php $meaning = $baseWord->senses->firstWhere('id', $baseWord->pivot->base_word_sense_id) ?? $baseWord->senses->first(); @endphp
                <span class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-1 text-sm">
                    <span>
                        {{ $baseWord->displayForm() }}
                        <span class="text-xs text-white/40">{{ $baseWord->part_of_speech }}</span>
                        @if($meaning)
                            <span class="text-xs text-white/60">· {{ $meaning->translation }}</span>
                        @endif
                    </span>
                    <button type="button" class="js-strike-word text-white/50 hover:text-white max-lg:-my-3 max-lg:-mr-3 max-lg:p-3"
                            data-url="/cards/{{ $card->id }}/words/{{ $baseWord->id }}" title="Strike: unlink it and ignore this word">✕</button>
                </span>
            @endforeach
            @foreach($card->fixedExpressions as $expression)
                <span class="inline-flex items-center gap-2 rounded-lg border border-blue-500/30 bg-blue-500/10 px-3 py-1 text-sm">
                    <span>{{ $expression->form }} <span class="text-xs text-white/40">expression</span></span>
                    <button type="button" class="js-strike-word text-white/50 hover:text-white max-lg:-my-3 max-lg:-mr-3 max-lg:p-3"
                            data-url="/cards/{{ $card->id }}/expressions/{{ $expression->id }}" title="Unlink this expression">✕</button>
                </span>
            @endforeach
            @if($card->baseWords->isEmpty() && $card->fixedExpressions->isEmpty())
                <p class="text-sm text-white/40">No words linked.</p>
            @endif
        </div>

        <div class="mt-4 max-w-lg">
            <input type="search" id="cardWordSearch" autocomplete="off" placeholder="Add a word from your base…"
                   class="w-full rounded-lg border border-white/10 bg-[#111] px-3 py-2 text-base text-white focus:outline-none">
            <ul id="cardWordResults" class="mt-2 divide-y divide-white/5 text-sm"></ul>
        </div>
    </section>

    <script>
        $(function () {
            const csrf = '{{ csrf_token() }}';
            const esc = text => $('<div>').text(text).html();
            let debounce;

            $('.js-strike-word').on('click', function () {
                $(this).prop('disabled', true);
                $.ajax({ url: $(this).data('url'), type: 'DELETE', data: { _token: csrf } })
                    .done(() => window.location.reload())
                    .fail(() => window.toastr && toastr.error('Could not strike it.'));
            });

            $('#cardWordSearch').on('input', function () {
                const q = $.trim($(this).val());
                clearTimeout(debounce);
                if (!q) { $('#cardWordResults').empty(); return; }

                debounce = setTimeout(function () {
                    $.get('/cards/{{ $card->id }}/word-search', { q: q }, function (data) {
                        const $list = $('#cardWordResults').empty();
                        if (!data.results.length) { $list.append('<li class="py-2 text-white/40">No such word in your base.</li>'); }

                        data.results.forEach(function (word) {
                            const $item = $('<li class="flex flex-wrap items-center gap-3 py-2">').appendTo($list);
                            $item.append('<span class="min-w-0 flex-1"><span class="font-bold">' + esc(word.display_form) + '</span> <span class="text-xs text-white/40">' + esc(word.part_of_speech) + '</span></span>');

                            // A word with several meanings: the learner says which one this card uses.
                            const $sense = $('<select class="js-word-sense rounded bg-white/10 px-2 py-1 text-sm max-lg:min-h-11">');
                            word.senses.forEach(sense => $sense.append($('<option>').val(sense.id).text(sense.translation)));
                            if (word.senses.length > 1) { $item.append($sense); }
                            else if (word.senses.length) { $item.append('<span class="text-xs text-white/60">' + esc(word.senses[0].translation) + '</span>'); }

                            $('<button type="button" class="shrink-0 rounded bg-blue-800 px-3 py-1 font-bold hover:bg-blue-600 max-lg:min-h-11">Add</button>')
                                .on('click', function () {
                                    $(this).prop('disabled', true);
                                    $.post('/cards/{{ $card->id }}/words', { _token: csrf, base_word_id: word.id, base_word_sense_id: $sense.val() || '' })
                                        .done(() => window.location.reload())
                                        .fail(() => window.toastr && toastr.error('Could not add it.'));
                                })
                                .appendTo($item);
                        });
                    });
                }, 250);
            });
        });
    </script>

    <x-forms.form method="post" action="/cards/{{$card->id}}/delete">
        <input hidden value="{{$card->id}}" name="id"/>
        <x-forms.button-delete>Delete term</x-forms.button-delete>
    </x-forms.form>
    <a href="/cards/{{$card->id}}" class="max-lg:mt-4 max-lg:inline-block">
        <x-forms.button-small class="max-lg:px-4">Back to card list</x-forms.button-small>
    </a>
</x-html-layout>
