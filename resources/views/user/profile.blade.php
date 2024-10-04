<x-html-layout>
    <div>
        <p class="text-5xl">{{Auth::user()->username}}</p>
{{--        <p class="text-lg">You can generate {{Auth::user()->currency_amount}} more cards.</p>--}}
        <p class="text-lg">The app is in beta. You can generate unlimited number of cards.</p>
        <p class="text-lg">You can't edit your profile just yet.</p>
    </div>
    <br>
    <a href="/themes/manage">
        <x-forms.button>Change themes</x-forms.button>
    </a>
</x-html-layout>
