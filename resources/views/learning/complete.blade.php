{{-- Below lg the choices stack into full-width buttons. --}}
<x-html-layout>
    <div class="mb-4">
        <p class="text-3xl">Set complete!</p>
    </div>
    <div class="mb-4 max-lg:flex max-lg:flex-col max-lg:gap-2">
        @if(session('more_cards_available'))
            <a href="/startLearning/{{session('learning_mode')}}">
                <x-forms.button class="max-lg:min-h-12 max-lg:w-full">Continue learning another set of cards</x-forms.button>
            </a>

        <span class="max-lg:text-center max-lg:text-white/50">or</span>
            <a href="/setLearning">
                <x-forms.button class="max-lg:min-h-12 max-lg:w-full">Switch learning mode</x-forms.button>
            </a>
            <br>
        @endif
    </div>
<div class="mb-4">
    {{-- Keep practising once the due cards run out, in the finished session's language. --}}
    @php $filter = session('learning_filter'); @endphp
    <a href="{{ route('frammenti', is_array($filter) && $filter['language_id'] ? ['language_id' => $filter['language_id']] : []) }}">
        <x-forms.button class="max-lg:min-h-12 max-lg:w-full">Play Frammenti</x-forms.button>
    </a>
</div>
<div>
    <a href="/">
        <x-forms.button-small class="max-lg:w-full">Back to home</x-forms.button-small>
    </a>
</div>
</x-html-layout>
