{{-- /base's title, the language filter and, right-aligned on the same row, the link to the
     ignored words and "Add word manually" for a language with a lexicon (docs/cards.md "Adding a
     word by hand", "Ignored words"). --}}
<h1 class="text-2xl font-bold mb-6">Vocabulary base</h1>

<div class="mb-4 flex flex-wrap items-center gap-2">
    @if($targetLanguages->count() > 1)
        @foreach($targetLanguages as $language)
            <a href="{{ route('base', ['language_id' => $language->id]) }}"
               @class([
                   'rounded-lg border border-white/10 px-3 py-1 text-sm transition-colors max-lg:py-2.5',
                   'bg-blue-600/30 ring-1 ring-blue-500' => (int) $activeLanguageId === $language->id,
                   'bg-white/5 hover:bg-white/10' => (int) $activeLanguageId !== $language->id,
               ])>
                {{ $language->flag }} {{ $language->name }}
            </a>
        @endforeach
    @endif

    <div class="ml-auto flex flex-wrap items-center gap-2">
        <a href="{{ route('base.ignored', ['language_id' => $activeLanguageId]) }}"
           class="px-2 py-1 text-sm text-white/60 hover:text-white hover:underline max-lg:py-2.5">Ignored words</a>
        @if($hasLexicon)
            <button type="button" onclick="openModal('add-base-word')"
                    class="rounded-lg border border-white/10 bg-white/5 px-3 py-1 text-sm transition-colors hover:bg-white/10 max-lg:py-2.5">
                + Add word manually
            </button>
        @endif
    </div>
</div>

@if($hasLexicon)
    <x-modal name="add-base-word" title="Add a word">
        <p class="mb-3 text-sm text-white/60">
            Search the dictionary and add a word straight to your base. Its translation is written for you.
        </p>
        <x-lexicon-search id="baseAddSearch" :language-id="$activeLanguageId" action="Add" />
    </x-modal>

    <script>
        $(function () {
            $('#baseAddSearch').on('lexicon:pick', function (e, result, $button) {
                $button.prop('disabled', true).text('Adding…');
                $.post('/base/words', {
                    _token: '{{ csrf_token() }}',
                    language_id: '{{ $activeLanguageId }}',
                    lemma: result.lemma,
                    part_of_speech: result.part_of_speech,
                }).done(function (data) {
                    // Open the base on the new word, picked.
                    window.location = data.url;
                }).fail(function (xhr) {
                    if (window.toastr) { toastr.error((xhr.responseJSON && xhr.responseJSON.message) || 'Could not add it.'); }
                    $button.prop('disabled', false).text('Add');
                });
            });
        });
    </script>
@endif
