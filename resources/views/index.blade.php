@props(['themes', 'wordboxes'])
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
                @php $singleLanguage = $targetLanguages->count() === 1; @endphp
                <div class="flex flex-col lg:flex-row gap-6 mt-6 items-start">
                    {{-- Capture form --}}
                    <div class="flex-grow w-full">
                        @unless($singleLanguage)
                            <p class="mb-2 text-sm text-white/70">
                                Saving to:
                                <span id="savingToName" class="font-bold text-blue-400">{{ $saveLanguageName }} - {{ $saveTargetName }}</span>
                            </p>
                        @endunless
                        <x-forms.form action="{{url('captureWordAjax')}}" method="post" id="addWord">
                            <input type="hidden" name="language_id" id="captureLanguageId" value="{{ $saveLanguageId }}">
                            <input type="hidden" name="wordbox_id" id="captureWordboxId" value="{{ $saveWordboxId }}">

                            <x-forms.input :label="false" name="capturedWord" id="captureWord"
                                           placeholder="Word or phrase to learn" class="flex-grow w-full min-w-[300px]"></x-forms.input>

                            <x-forms.input :label="false" name="context" id="context"
                                           placeholder="(Optional) Add context, like a sentence or brief description of the term..."></x-forms.input>

                            @if($singleLanguage)
                                {{-- Single language: wordbox "tags" live right below the context input. --}}
                                @php $only = $targetLanguages->first(); $boxes = $wordboxesByLanguage[$only->id] ?? collect(); @endphp
                                <div id="savePicker" class="mt-3">
                                    <div class="tag-row flex items-center gap-2">
                                        <div class="tag-inline flex flex-nowrap gap-2 overflow-hidden grow">
                                            @foreach($boxes as $box)
                                                @php $boxSelected = $saveWordboxId == $box->id; @endphp
                                                <button type="button"
                                                        class="save-node tag flex items-center gap-1.5 text-xs px-3 py-1 border border-white/10 hover:bg-white/10 transition-colors whitespace-nowrap {{ $boxSelected ? 'bg-blue-600/30 ring-1 ring-blue-500' : 'bg-white/5' }}"
                                                        data-order="{{ $loop->index }}"
                                                        data-language-id="{{ $only->id }}" data-wordbox-id="{{ $box->id }}"
                                                        data-language-name="{{ $only->name }}" data-name="{{ $box->name }}">
                                                    <span>{{ $box->name }}</span>
                                                    <span class="cross {{ $boxSelected ? '' : 'hidden' }} ml-1 text-white/60 hover:text-white">✕</span>
                                                </button>
                                            @endforeach
                                        </div>
                                        <div class="more-wrap relative shrink-0 hidden">
                                            <button type="button" class="more-btn flex items-center gap-1 text-xs px-3 py-1 bg-white/5 border border-white/10 hover:bg-white/10 transition-colors whitespace-nowrap">More <span class="text-[10px]">▾</span></button>
                                            <div class="more-list hidden absolute right-0 mt-1 z-30 min-w-[12rem] max-h-64 overflow-auto p-2 rounded-xl bg-neutral-900 border border-white/10 shadow-xl flex flex-col gap-1"></div>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div class="flex mt-4">
                                <x-forms.button id="btnAdd">Add</x-forms.button>
                                <p id="info_creatingCard" class="hidden ml-3 font-bold">Creating card... Please wait</p>
                            </div>
                        </x-forms.form>
                    </div>

                    {{-- Save-destination picker (multi-language only) --}}
                    @unless($singleLanguage)
                        <div class="w-full lg:w-96 shrink-0">
                            <div class="bg-white/5 rounded-xl border border-white/10 p-4">
                                <h3 class="font-bold mb-3">Save destination</h3>
                                <div id="savePicker" class="space-y-4">
                                    @foreach($targetLanguages as $lang)
                                        @php $boxes = $wordboxesByLanguage[$lang->id] ?? collect(); $genSelected = $saveLanguageId == $lang->id && ! $saveWordboxId; @endphp
                                        <div class="lang-group" data-language-id="{{ $lang->id }}">
                                            <button type="button"
                                                    class="save-node lang-row flex items-center gap-2 w-full text-left px-4 py-2 rounded-lg border border-white/10 hover:bg-white/10 transition-colors {{ $genSelected ? 'bg-blue-600/30 ring-1 ring-blue-500' : 'bg-white/5' }}"
                                                    data-language-id="{{ $lang->id }}" data-wordbox-id=""
                                                    data-language-name="{{ $lang->name }}" data-name="General vocabulary">
                                                <span class="grow">{{ $lang->flag }} {{ $lang->name }} <span class="text-white/40">— General vocabulary</span></span>
                                                <span class="check {{ $genSelected ? '' : 'hidden' }}">✓</span>
                                            </button>
                                            @if($boxes->count())
                                                <div class="tag-row flex items-center gap-2 mt-2 pl-2">
                                                    <div class="tag-inline flex flex-nowrap gap-2 overflow-hidden grow">
                                                        @foreach($boxes as $box)
                                                            @php $boxSelected = $saveLanguageId == $lang->id && $saveWordboxId == $box->id; @endphp
                                                            <button type="button"
                                                                    class="save-node tag flex items-center gap-1.5 text-xs px-3 py-1 border border-white/10 hover:bg-white/10 transition-colors whitespace-nowrap {{ $boxSelected ? 'bg-blue-600/30 ring-1 ring-blue-500' : 'bg-white/5' }}"
                                                                    data-order="{{ $loop->index }}"
                                                                    data-language-id="{{ $lang->id }}" data-wordbox-id="{{ $box->id }}"
                                                                    data-language-name="{{ $lang->name }}" data-name="{{ $box->name }}">
                                                                <span>{{ $box->name }}</span>
                                                                <span class="cross {{ $boxSelected ? '' : 'hidden' }} ml-1 text-white/60 hover:text-white">✕</span>
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                    <div class="more-wrap relative shrink-0 hidden">
                                                        <button type="button" class="more-btn flex items-center gap-1 text-xs px-3 py-1 bg-white/5 border border-white/10 hover:bg-white/10 transition-colors whitespace-nowrap">More <span class="text-[10px]">▾</span></button>
                                                        <div class="more-list hidden absolute right-0 mt-1 z-30 min-w-[12rem] max-h-64 overflow-auto p-2 rounded-xl bg-neutral-900 border border-white/10 shadow-xl flex flex-col gap-1"></div>
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endunless
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
            $modalLangId = $saveLanguageId ?? optional($targetLanguages->first())->id;
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
            const addPostButton = document.getElementById("btnAdd");
            if (addPostButton) {
                addPostButton.addEventListener("click", function () {
                    document.getElementById("info_creatingCard").classList.remove("hidden");
                    addPostButton.classList.add("hidden");
                });
            }

            // Save-destination picker: choose the language + wordbox a new word is saved to.
            $(document).ready(function () {
                const $picker = $('#savePicker');
                if (! $picker.length) { return; }

                const SELECTED = 'bg-blue-600/30 ring-1 ring-blue-500';

                // Highlight the active node (brighter + ✕ on a selected wordbox tag) and mark a
                // "More" button whose dropdown currently holds the selection.
                function applySelection(languageId, wordboxId) {
                    languageId = String(languageId);
                    wordboxId = String(wordboxId || '');
                    $picker.find('.save-node').each(function () {
                        const $n = $(this);
                        const sel = String($n.data('language-id')) === languageId
                            && String($n.data('wordbox-id') || '') === wordboxId;
                        $n.toggleClass(SELECTED, sel);
                        $n.toggleClass('bg-white/5', ! sel);
                        $n.find('.check').toggleClass('hidden', ! sel);
                        $n.find('.cross').toggleClass('hidden', ! sel);
                    });
                    $picker.find('.more-wrap').each(function () {
                        const has = $(this).find('.save-node.ring-blue-500').length > 0;
                        $(this).find('.more-btn').toggleClass('ring-1 ring-blue-500', has);
                    });
                }

                // Persist + reflect a chosen save destination (no page reload).
                function selectTarget(languageId, wordboxId, langName, name, row) {
                    wordboxId = wordboxId || '';
                    applySelection(languageId, wordboxId);
                    $('#captureLanguageId').val(languageId);
                    $('#captureWordboxId').val(wordboxId);
                    $('#savingToName').text(langName + ' - ' + name);
                    $picker.find('.more-list').addClass('hidden');
                    if (row) { layoutTagRow(row); }

                    $.ajax({
                        url: "{{ route('capture-target') }}",
                        method: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}',
                            language_id: languageId,
                            wordbox_id: wordboxId
                        },
                        error: function () {
                            toastr.error('Could not update the save destination.');
                        }
                    });
                }

                // Click a node → select it.
                $picker.on('click', '.save-node', function () {
                    const $node = $(this);
                    selectTarget($node.data('language-id'), $node.data('wordbox-id') || '',
                        $node.data('language-name'), $node.data('name'), this.closest('.tag-row'));
                });

                // Click the ✕ on a selected wordbox tag → unselect (back to General vocabulary).
                $picker.on('click', '.cross', function (e) {
                    e.stopPropagation();
                    const $node = $(this).closest('.save-node');
                    selectTarget($node.data('language-id'), '', $node.data('language-name'),
                        'General vocabulary', this.closest('.tag-row'));
                });

                // Overlay "More" dropdown for overflowing wordbox tags.
                $picker.on('click', '.more-btn', function (e) {
                    e.stopPropagation();
                    const $list = $(this).siblings('.more-list');
                    const isOpen = ! $list.hasClass('hidden');
                    $picker.find('.more-list').addClass('hidden');
                    if (! isOpen) { $list.removeClass('hidden'); }
                });
                $(document).on('click', function (e) {
                    if (! $(e.target).closest('.more-wrap').length) {
                        $picker.find('.more-list').addClass('hidden');
                    }
                });

                // Keep wordbox tags on one line; overflow goes into the "More" dropdown. The
                // selected tag is always kept visible (swapped in for the last one or two tags).
                function layoutTagRow(row) {
                    const inline = row.querySelector('.tag-inline');
                    const moreWrap = row.querySelector('.more-wrap');
                    if (! inline || ! moreWrap) { return; }
                    const moreList = moreWrap.querySelector('.more-list');

                    // Reset every tag back inline, in its defined order.
                    const all = Array.from(row.querySelectorAll('.tag'))
                        .sort((a, b) => (+a.dataset.order) - (+b.dataset.order));
                    all.forEach(t => inline.appendChild(t));
                    moreWrap.classList.add('hidden');
                    if (! all.length) { return; }

                    const gap = 8;
                    const avail = row.clientWidth;
                    let total = 0;
                    all.forEach((t, i) => { total += t.offsetWidth + (i > 0 ? gap : 0); });
                    if (total <= avail) { return; }

                    // Overflow: reserve room for "More", then greedily keep what fits.
                    moreWrap.classList.remove('hidden');
                    const limit = avail - (moreWrap.offsetWidth + gap);
                    const visible = [], overflow = [];
                    let used = 0, full = false;
                    all.forEach(t => {
                        const w = t.offsetWidth + (visible.length ? gap : 0);
                        if (! full && used + w <= limit) { used += w; visible.push(t); }
                        else { full = true; overflow.push(t); }
                    });

                    // Make sure the selected wordbox tag stays visible: swap it in for the
                    // last visible tag, or the last two if it still doesn't fit.
                    const selId = String($('#captureWordboxId').val() || '');
                    if (selId) {
                        const sel = overflow.find(t => String(t.dataset.wordboxId) === selId);
                        if (sel) {
                            overflow.splice(overflow.indexOf(sel), 1);
                            for (let n = 0; n < 2 && visible.length; n++) {
                                const demoted = visible.pop();
                                overflow.unshift(demoted);
                                used -= demoted.offsetWidth + gap;
                                if (used + sel.offsetWidth + gap <= limit) { break; }
                            }
                            visible.push(sel);
                        }
                    }

                    visible.forEach(t => inline.appendChild(t));
                    overflow.forEach(t => moreList.appendChild(t));
                }

                function layoutAll() {
                    document.querySelectorAll('#savePicker .tag-row').forEach(layoutTagRow);
                    applySelection($('#captureLanguageId').val(), $('#captureWordboxId').val());
                }

                layoutAll();
                let resizeTimer;
                $(window).on('resize', function () {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(layoutAll, 150);
                });
            });

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

            window.onload = function() {
                const capture = document.getElementById('captureWord');
                if (capture) { capture.focus(); }
            };
        </script>

    @endauth
</x-html-layout>
@if(session('popup_message'))
    <script>
        alert("{{ session('popup_message') }}");
    </script>
@endif
