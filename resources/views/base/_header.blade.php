{{-- /base's title, Frammenti and the language filter. --}}
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-2xl font-bold">Vocabulary base</h1>
        <p class="text-sm text-white/50">
            The words you are learning in this language, one entry per lemma and part of
            speech, and the fixed expressions you learn as wholes. Neither carries a review
            schedule — that lives on your cards — but both are what Frammenti practises.
        </p>
    </div>
    <a href="{{ route('frammenti', ['language_id' => $activeLanguageId]) }}">
        <x-forms.button>Frammenti</x-forms.button>
    </a>
</div>

@if($targetLanguages->count() > 1)
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach($targetLanguages as $language)
            <a href="{{ route('base', ['language_id' => $language->id]) }}"
               @class([
                   'rounded-lg border border-white/10 px-3 py-1 text-sm transition-colors',
                   'bg-blue-600/30 ring-1 ring-blue-500' => (int) $activeLanguageId === $language->id,
                   'bg-white/5 hover:bg-white/10' => (int) $activeLanguageId !== $language->id,
               ])>
                {{ $language->flag }} {{ $language->name }}
            </a>
        @endforeach
    </div>
@endif
