<x-html-layout>
    {{--@guest
    <div class="flex justify-center">
        <img width="800px" src="{{Vite::asset('resources/images/logo_guestScreen.jpg')}}" alt="Improve your language skills with Frase!">
    </div>
    @endguest--}}

    <section>
        <x-section-heading>Capture</x-section-heading>
        <x-forms.form action="/captureWord" class="mt-6">
            <x-forms.input :label="false" name="captureWord" placeholder="Write a word or phrase"></x-forms.input>
            <x-forms.button>Save</x-forms.button>
        </x-forms.form>
    </section>

    <section>
        <x-section-heading>Themes</x-section-heading>
        <div class="grid lg:grid-cols-3 gap-8 mt-6">
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>
            <x-theme-card></x-theme-card>

        </div>
    </section>
</x-html-layout>
