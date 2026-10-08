<x-html-layout>
    <div class="mb-4">
        <p class="text-3xl">Set complete!</p>
    </div>
    <div class="mb-4">
        @if(session('more_cards_available'))
            <a href="/startLearning/0/{{session('learning_mode')}}">
                <x-forms.button>Continue learning another set of cards</x-forms.button>
            </a>

        <span>or</span>
            <a href="/setLearning">
                <x-forms.button>Switch learning mode</x-forms.button>
            </a>
            <br>
        @endif
    </div>
<div class="mb-4">
    {{-- Keep practising once the due cards run out, in the finished session's language. --}}
    @php $filter = session('learning_filter'); @endphp
    <a href="{{ route('frammenti', is_array($filter) && $filter['language_id'] ? ['language_id' => $filter['language_id']] : []) }}">
        <x-forms.button>Play Frammenti</x-forms.button>
    </a>
</div>
<div>
    <a href="/">
        <x-forms.button-small>Back to home</x-forms.button-small>
    </a>
</div>
</x-html-layout>
