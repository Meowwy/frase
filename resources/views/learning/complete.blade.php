<x-html-layout>
    <div class="mb-4">
        <p class="text-3xl">Set complete!</p>
    </div>
    <div class="mb-4">
        @if(session('more_cards_available'))
            <a href="/startLearning/{{session('learning_mode')}}">
                <x-forms.button>Continue learning another set of cards</x-forms.button>
            </a>

        <span>or</span>
            <a href="/setLearning">
                <x-forms.button>Switch learning mode</x-forms.button>
            </a>
            <br>
        @endif
    </div>
<div>
    <a href="/">
        <x-forms.button-small>Back to home</x-forms.button-small>
    </a>
</div>
</x-html-layout>
