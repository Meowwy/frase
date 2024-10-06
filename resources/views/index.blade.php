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
            <p class="text-xl">Most of language learning is too generic. We need a way to learn naturally by capturing expressions we encounter in our everyday life.</p>

            <p class="text-xl mt-3">Frase offers a range of features to help anyone improve their language skills.</p>
            <div class="grid lg:grid-cols-3 gap-5 mt-4">
                <x-card-text heading="Everything is autonomous" text="Just capture words or phrases you find useful! Frase handles everything from creating flashcards to organizing them automatically."></x-card-text>
                <x-card-text heading="Learning in context" text="By presenting words and phrases in relevant contexts, Frase makes them simpler to remember and use effectively."></x-card-text>
                <x-card-text heading="Build a strong vocabulary for the life you live" text="Using a non-native language daily? Making a small effort to improve each day will have huge impact over time."></x-card-text>
                <x-card-text heading="Not only a storage" text="Frase does more than store words; it helps you learn the expressions you’ve saved so you can use them confidently in real life."></x-card-text>
                <x-card-text heading="Active learning that is also fun" text="Frase makes learning fun and interactive with various methods designed to help you actively engage and retain new vocabulary in your long-term memory."></x-card-text>
                <x-card-text heading="Master Foreign Terminology" text="Whether it’s for work, travel, or study, Frase allows you to collect and learn any foreign terms, making them accessible whenever you need them."></x-card-text>
            </div>
        </section>
        {{--<div class="flex justify-center">
            <img width="800px" src="{{Vite::asset('resources/images/logo_guestScreen.jpg')}}" alt="Improve your language skills with Frase!">
        </div>--}}
    @endguest

    @auth
        <section>
            <x-section-heading>capture a term</x-section-heading>
            <x-forms.form action="{{url('captureWordAjax')}}" method="post" id="addWord" class="mt-6">
                <x-forms.input :label="false" name="capturedWord" id="captureWord"
                               placeholder="Write a word or phrase in English"></x-forms.input>
                <x-forms.button id="btnAdd">Add</x-forms.button>
            </x-forms.form>
        </section>

        <section>
            <x-section-heading>quick navigation</x-section-heading>
            <div class="flex items-center justify-center space-x-10 mt-6">
                <x-panel>
                    @if($dueCount === 0)
                        <div class="flex flex-col space-y-2">
                            <p class="text-center">Nothing to learn for today!</p>
                        </div>
                    @else
                        <div class="flex flex-col space-y-2">
                            <p class="text-center">{{$dueCount}} cards to learn today</p>
                            <x-forms.form method="GET" action="/filterCardsForLearning/due" class="text-center">
                                <x-forms.button-confirm>Learn due cards</x-forms.button-confirm>
                            </x-forms.form>
                        </div>

                    @endif
                    <div>

                    </div>
                </x-panel>
                    <x-panel>
                        <div class="flex flex-col space-y-2">
                            <p>You have saved {{$totalCount}} terms</p>
                            <a href="/cards" class="flex flex-col items-center">
                                <x-forms.button-confirm>Browse terms</x-forms.button-confirm>
                            </a>
                        </div>

                    </x-panel>
                <x-panel>
                    <div class="flex flex-col space-y-2">
                        <p>Insert a term manually</p>
                        <a href="/add" class="flex flex-col items-center">
                            <x-forms.button-confirm>Add card</x-forms.button-confirm>
                        </a>
                    </div>

                </x-panel>

            </div>

        </section>

        <section>
            <x-section-heading>themes</x-section-heading>
            @if(count($themes) === 0)
                <div class="flex justify-center">
                    <div class="flex flex-col items-center justify-center">
                        <p>When you define themes, each term you add will be automatically assigned to a theme that is most appropriate.</p>
                        <a href="/themes/manage">
                            <x-forms.button>Define themes</x-forms.button>
                        </a>
                    </div>

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
@if(session('popup_message'))
    <script>
        alert("{{ session('popup_message') }}");
    </script>
@endif
