{{-- /base's title and the language filter. --}}
<h1 class="text-2xl font-bold mb-6">Vocabulary base</h1>

@if($targetLanguages->count() > 1)
    <div class="mb-4 flex flex-wrap gap-2">
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
    </div>
@endif
