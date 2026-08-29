@props(['card'])

{{-- The "learn it in a phrase instead" nudge. Only a lexical card built around a single
     word carries usage fragments, so this renders nothing for a phrase or expression
     card. Clicking a fragment builds a card around that phrase and replaces this one —
     see CardController@learnAsPhrase. --}}
@php($suggestions = $card->suggestedPhrases())

@if(!empty($suggestions))
    <div class="mb-6 js-phrase-suggestions" data-card-id="{{ $card->id }}">
        <p class="mb-2 text-sm text-white/60">
            Words stick better inside a phrase — pick one to learn instead:
        </p>
        <div class="flex flex-wrap gap-3">
            @foreach($suggestions as $suggestion)
                <button type="button"
                        class="js-learn-as-phrase rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm text-white/90 hover:bg-white/10 hover:border-white/30 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        data-phrase="{{ $suggestion }}">
                    {{ $suggestion }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Self-contained, like <x-wordbox-picker>: the component owns its own behaviour so
         a consuming page never has to wire it up. Only one instance renders per page. --}}
    <script>
        $(document).ready(function () {
            const $block = $('.js-phrase-suggestions');
            const cardId = $block.data('card-id');
            const csrf = '{{ csrf_token() }}';

            $block.on('click', '.js-learn-as-phrase', function () {
                const $buttons = $block.find('.js-learn-as-phrase');
                const $clicked = $(this);

                // The whole set is disabled: the card is about to be replaced, so a
                // second choice would race the first.
                $buttons.prop('disabled', true);
                $clicked.text('Creating card…');

                $.post('/cards/' + cardId + '/learn-as-phrase', {
                    phrase: $clicked.data('phrase'),
                    _token: csrf
                }).done(function (data) {
                    window.location.href = data.redirect;
                }).fail(function (xhr) {
                    const res = xhr.responseJSON || {};
                    if (window.toastr) { toastr.error(res.message || 'Could not create that card.'); }
                    $buttons.prop('disabled', false);
                    $clicked.text($clicked.data('phrase'));
                });
            });
        });
    </script>
@endif
