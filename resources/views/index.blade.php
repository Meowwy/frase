@props(['themes'])
<x-html-layout>
    @guest
        <section class="mb-7">
            <div class="flex flex-col items-center justify-center">
                <p class="font-bold text-6xl italic">Advance in languages</p>
                <p class="mt-3 font-bold text-6xl">in your own way</p>
                <div class="mt-8 text-2xl">
                    <span class="mr-1 font-bold bg-orange-700 text-white rounded-full px-3 py-1">
                        Personal vocabulary management system
                    </span>
                    <span class="font-bold">that helps you improve in a meaningful way.</span>
                </div>

            </div>
        </section>
        <div>
            <div class="bg-white/10 my-10 h-px w-full"></div>
        </div>
        <section class="">
            <x-page-heading>Why you should switch to Frase?</x-page-heading>
            <p class="text-xl">Most of language learning is too generic. We need space to learn naturally by capturing the expressions we encounter in our everyday life.</p>

            <p class="text-xl mt-3">For everyone interested in improving their language skills, Frase offers bunch of features to assist you.</p>
            <div class="grid lg:grid-cols-3 gap-5 mt-4">
                <x-card-text heading="Everything is autonomous" text="Just capture words or phrases you find useful! Everything from creating flashcards to organising them is done automatically."></x-card-text>
                <x-card-text heading="Learning in context" text="Frase will expose you to words or phrases in ways it is easier to remember by making connection."></x-card-text>
                <x-card-text heading="Building strong vocabulary base for the life we live" text="A lot of us use non-native language every day. Making a little effort to improve each day will have huge impact over time."></x-card-text>
                <x-card-text heading="Not only a storage" text="Frase is not only for storage, its main purpose is to teach you the expressions you saved so you can use them without hesitation in real life."></x-card-text>
                <x-card-text heading="Active learning that is also fun" text="In Frase you actively interact with your vocabulary base by various learning methods, which offer a unique and engaging way to get the knowledge to your long term memory."></x-card-text>


            </div>
        </section>
        {{--<div class="flex justify-center">
            <img width="800px" src="{{Vite::asset('resources/images/logo_guestScreen.jpg')}}" alt="Improve your language skills with Frase!">
        </div>--}}
    @endguest

    @auth

        <section>
            <x-section-heading>Capture</x-section-heading>
            <x-forms.form action="{{url('captureWordAjax')}}" method="post" id="addWord" class="mt-6">
                <x-forms.input :label="false" name="capturedWord"
                               placeholder="Write a word or phrase in English"></x-forms.input>
                <x-forms.button>Save</x-forms.button>
            </x-forms.form>
        </section>

        <section>
            <x-section-heading>Navigation</x-section-heading>
            <div class="flex">
                <div>
                    @if($dueCount === 0)
                        <p class="mt-4 text-center">Nothing to learn for today!</p>
                    @else
                        <p class="mt-4 text-center">{{$dueCount}} cards to learn</p>
                        <x-forms.form method="GET" action="/filterCardsForLearning/due" class="text-center">
                            <x-forms.button-confirm>Learn due cards</x-forms.button-confirm>
                        </x-forms.form>
                    @endif
                    <div>

                    </div>
                </div>
                <div>
                    <x-panel>
                        <a href="/cards">Show all cards</a>
                    </x-panel>
                </div>
            </div>

        </section>

        <section>
            <x-section-heading>Themes</x-section-heading>
            @if(!$themes)
                <div>
                    <p>You haven't decided how you are going to organize your vocabulary base, yet.</p>
                </div>
            @endif

            <div class="grid lg:grid-cols-3 gap-8 mt-6">
                @auth
                    @foreach($themes as $theme)
                        <x-theme-card :theme="$theme"/>
                    @endforeach
                @endauth


            </div>
        </section>

        <script type="text/javascript">
            $(document).ready(function () {
                $('#addpost').on('submit', function (event) {
                    event.preventDefault();
                    jQuery.ajax({
                        url: "{{url('captureWordAjax')}}",
                        data: jQuery('#addWord').serialize(),
                        type: post,

                        success: function (result) {
                            toastr.success("captured");
                            $('#addWord')[0].reset();
                        },
                        error: function (xhr) {
                            alert('An error occurred: ' + xhr.responseJSON.error);
                        }

                    })
                })
            });
        </script>

    @endauth
</x-html-layout>
