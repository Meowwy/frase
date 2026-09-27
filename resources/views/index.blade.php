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
            <p class="text-2xl border border-gray-300 p-4 m-4">
                Most of language learning is too generic. We need a way to learn naturally by capturing expressions we encounter in our everyday life.
            </p>

            <p class="text-xl mt-3">Frase offers a range of features to help anyone improve their language skills.</p>
            <div class="grid lg:grid-cols-3 gap-5 mt-4">
                <x-card-text heading="Everything is Autonomous" text="Just capture words or phrases you find useful! Frase handles everything from creating flashcards to organizing them automatically."></x-card-text>
                <x-card-text heading="Learning in Context" text="By presenting words and phrases in relevant contexts, Frase makes them simpler to remember and use effectively."></x-card-text>
                <x-card-text heading="Build a Strong Vocabulary for the life you live" text="Using a non-native language daily? Making a small effort to improve each day will have huge impact over time."></x-card-text>
                <x-card-text heading="Not Only a Storage" text="Frase does more than store words; it helps you learn the expressions you’ve saved so you can use them confidently in real life."></x-card-text>
                <x-card-text heading="Active Learning" text="Frase makes learning fun and interactive with various methods designed to help you actively engage and retain new vocabulary in your long-term memory."></x-card-text>
                <x-card-text heading="Master Foreign Terminology" text="Whether it’s for work, travel, or study, Frase allows you to collect and learn any foreign terms, making them accessible whenever you need them."></x-card-text>
            </div>
        </section>
        {{--<div class="flex justify-center">
            <img width="800px" src="{{Vite::asset('resources/images/logo_guestScreen.jpg')}}" alt="Improve your language skills with Frase!">
        </div>--}}
    @endguest

    @auth
        <section>
            <x-section-heading>capture a term into Frase</x-section-heading>

            @if($targetLanguages->isEmpty())
                <div class="mt-6 bg-white/5 rounded-xl border border-white/10 p-4">
                    <div class="flex flex-col items-center text-center gap-3 py-6">
                        <p>Choose the language(s) you want to learn before saving words.</p>
                        <a href="/profile/edit"><x-forms.button>Set up your languages</x-forms.button></a>
                    </div>
                </div>
            @else
                <div class="mt-6 flex justify-center">
                    <x-capture-form :autofocus="true" />
                </div>
            @endif
        </section>

        <section class="my-8 mb-12">
            <div class="bg-white/5 rounded-xl border border-white/10 p-4">
                @if($dueLanguages->isNotEmpty())
                    <div class="flex flex-wrap justify-center">
                        @foreach($dueLanguages as $language)
                            <x-learning-due-card :language="$language" />
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center text-center py-10">
                        <p class="text-3xl font-bold tracking-wide text-orange-800">ALL DONE</p>
                        <p class="mt-2 text-white/60">All cards reviewed — nothing due right now.</p>
                    </div>
                @endif
            </div>
        </section>

        @if($recentCards->isNotEmpty())
            <section class="mb-8">
                <h2 class="text-lg font-bold mb-4">Recently added</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700 bg-white/5">
                        <thead>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Term</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Translation</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700">
                        @foreach($recentCards as $card)
                            <tr class="hover:bg-white/10 cursor-pointer" onclick="window.location='/cards/{{ $card->id }}'">
                                <td class="px-6 py-2 text-sm text-white">{{ $card->term }}</td>
                                <td class="px-6 py-2 text-sm text-gray-300">{{ $card->translation }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section>
            <div class="bg-white/5 rounded-xl border border-white/10 p-4">
                <div class="flex">
                    <!-- Left Section (1/4 width) -->
                    <div class="w-1/4 p-6 flex flex-col justify-center">
                        <h2 class="text-lg font-bold mb-2">Create Wordbox</h2>
                        <p class="text-sm mb-4">
                            Wordboxes let you create separate decks of learning cards.
                        </p>
                        <x-forms.button onclick="openModal('create-wordbox')">Create a wordbox</x-forms.button>
                    </div>

                    <!-- Right Section (3/4 width) -->
                    <div class="w-3/4 p-6 overflow-hidden">
                        <h2 class="text-lg font-bold mb-4">Your wordboxes</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach($wordboxes as $wordbox)
                                <div class="bg-white/10 p-4 rounded-lg flex flex-col justify-between h-full border border-white/10 hover:border-blue-500 transition-colors">
                                    <div>
                                        <h3 class="text-xl font-bold mb-2 truncate" title="{{ $wordbox->name }}">{{ $wordbox->name }}</h3>
                                        <p class="text-sm text-white/70 line-clamp-2 mb-2">{{ $wordbox->description }}</p>
                                        <p class="text-xs font-semibold text-blue-400 uppercase tracking-wider">Cards: {{ $wordbox->cards_count }}</p>
                                    </div>
                                    <a href="/wordbox/{{ $wordbox->id }}" class="mt-4">
                                        <x-forms.button-small class="w-full">View details</x-forms.button-small>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

        </section>

        @php
            $modalMulti = $targetLanguages->count() > 1;
            $modalLangId = $activeLanguageId ?? optional($targetLanguages->first())->id;
        @endphp
        <x-modal name="create-wordbox" title="Create New Wordbox">
            <x-forms.form action="/wordbox/new" method="POST">
                <x-forms.input label="Name" name="name" placeholder="e.g. Travel Vocabulary" required />
                <x-forms.textarea label="Description" name="description" placeholder="Optional description of this wordbox..." />

                @if($modalMulti)
                    <div class="mb-4">
                        <label class="block mb-1 text-white/70">Language</label>
                        <div class="combo relative">
                            <button type="button" id="wbLangTrigger"
                                    class="w-full flex items-center justify-between rounded-xl bg-white/10 border border-white/10 px-4 py-2 hover:border-blue-500 transition-colors">
                                <span id="wbLangLabel"></span>
                                <span class="text-white/50 text-xs">▾</span>
                            </button>
                            <div id="wbLangMenu" class="hidden absolute z-30 left-0 right-0 mt-1 max-h-60 overflow-auto rounded-xl bg-neutral-900 border border-white/10 shadow-xl py-1">
                                @foreach($targetLanguages as $lang)
                                    <button type="button"
                                            class="wb-lang-option w-full text-left text-sm px-3 py-2 hover:bg-blue-600/30 transition-colors"
                                            data-id="{{ $lang->id }}" data-label="{{ $lang->flag }} {{ $lang->name }}">
                                        {{ $lang->flag }} {{ $lang->name }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        <input type="hidden" name="language_id" id="wbLangInput" value="{{ $modalLangId }}">
                    </div>
                @endif

                <div class="flex justify-end gap-x-2">
                    <x-forms.button type="button" class="bg-gray-600 hover:bg-gray-500" onclick="closeModal('create-wordbox')">Cancel</x-forms.button>
                    <x-forms.button>Create Wordbox</x-forms.button>
                </div>
            </x-forms.form>
        </x-modal>

        <script type="text/javascript">
            // Create-wordbox modal: language picker (custom overlay combo, multi-language users).
            $(document).ready(function () {
                const trigger = document.getElementById('wbLangTrigger');
                if (! trigger) { return; }
                const menu = document.getElementById('wbLangMenu');
                const label = document.getElementById('wbLangLabel');
                const input = document.getElementById('wbLangInput');

                function syncLabel() {
                    const sel = menu.querySelector('.wb-lang-option[data-id="' + input.value + '"]')
                        || menu.querySelector('.wb-lang-option');
                    if (sel) { input.value = sel.dataset.id; label.textContent = sel.dataset.label; }
                }
                syncLabel();

                trigger.addEventListener('click', function (e) {
                    e.stopPropagation();
                    menu.classList.toggle('hidden');
                });
                menu.querySelectorAll('.wb-lang-option').forEach(function (o) {
                    o.addEventListener('click', function () {
                        input.value = o.dataset.id;
                        label.textContent = o.dataset.label;
                        menu.classList.add('hidden');
                    });
                });
                document.addEventListener('click', function () { menu.classList.add('hidden'); });
            });
        </script>

    @endauth
</x-html-layout>
@if(session('popup_message'))
    <script>
        alert("{{ session('popup_message') }}");
    </script>
@endif
