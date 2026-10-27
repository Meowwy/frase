@props([
    'targetLanguages',
    'activeLanguageId',
])

{{-- Shared language switcher for the pages that re-filter in place (/cards, /setLearning):
     one tab per target language, shown only when there is more than one. Switching makes no
     request of its own — it fires a 'languageswitcher:change' DOM event ({ languageId }) and
     the page decides what to do with it. --}}

@if($targetLanguages->count() > 1)
    <div id="langSwitcher" class="flex gap-2 mb-2">
        @foreach($targetLanguages as $lang)
            <button type="button"
                    class="lang-tab flex-1 flex items-center justify-center gap-2 px-4 py-2 rounded-lg border border-white/10 hover:bg-white/10 transition-colors {{ $lang->id == $activeLanguageId ? 'bg-blue-600/30 ring-1 ring-blue-500' : 'bg-white/5' }}"
                    data-language-id="{{ $lang->id }}">
                <span>{{ $lang->flag }} {{ $lang->name }}</span>
            </button>
        @endforeach
    </div>

    <script>
        $(document).ready(function () {
            const SELECTED = 'bg-blue-600/30 ring-1 ring-blue-500';

            $('#langSwitcher').on('click', '.lang-tab', function () {
                const languageId = String($(this).data('language-id'));
                $('#langSwitcher .lang-tab').each(function () {
                    const sel = String($(this).data('language-id')) === languageId;
                    $(this).toggleClass(SELECTED, sel).toggleClass('bg-white/5', !sel);
                });
                document.dispatchEvent(new CustomEvent('languageswitcher:change', { detail: { languageId } }));
            });
        });
    </script>
@endif
