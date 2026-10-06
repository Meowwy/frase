{{-- One row per proposal: a skeleton while CALL 1 is still running, the real thing once it
     has landed. Everything the learner can do to a proposal re-renders this whole list from
     the server, so the approvability rules live in PHP only (see Proposal). --}}

@forelse($proposals as $proposal)
    @php
        $pending = $proposal->isAwaitingAnalysis();
        $failed = $proposal->hasFailed();
        $duplicates = $pending || $failed ? collect() : $proposal->duplicateCards();
        $blocked = $pending || $failed ? collect() : $proposal->blockingDuplicates();
    @endphp

    <div class="rounded-xl border border-white/10 bg-white/5 p-4 js-proposal" data-proposal-id="{{ $proposal->id }}">
        @if($pending)
            <div class="flex items-center gap-3">
                <span class="h-4 w-32 animate-pulse rounded bg-white/20"></span>
                <span class="text-sm text-white/40">reading "{{ $proposal->raw_input }}"…</span>
            </div>
        @elseif($failed)
            {{-- CALL 1 failed or stalled (see Proposal::hasFailed()). --}}
            <div class="flex items-center gap-3">
                <span class="text-red-400">✕</span>
                <span class="text-sm text-white/60">
                    Could not read <span class="font-bold text-white">{{ $proposal->raw_input }}</span>
                </span>
                <x-forms.button-small class="js-retry ml-auto">Try again</x-forms.button-small>
                <button type="button" class="js-discard text-white/50 hover:text-white" title="Delete">🗑</button>
            </div>
        @elseif($proposal->language_options)
            {{-- The language picker: the Term is a real word in more than one of the
                 learner's languages, so CALL 1 asks before extracting anything. Picking one
                 pins it and re-runs CALL 1 (the same call as correcting the language). --}}
            <div class="border-b border-white/10 pb-3">
                <span class="js-term text-2xl font-medium break-words">{{ $proposal->term }}</span>
            </div>
            <div class="mt-3 space-y-1 text-sm">
                <p class="text-white/60">Which language is this?</p>
                @foreach($proposal->language_options as $languageId)
                    @php $language = $targetLanguages->firstWhere('id', $languageId); @endphp
                    @if($language)
                        <label class="flex items-baseline gap-2 cursor-pointer">
                            <input type="radio" name="language-{{ $proposal->id }}" class="js-language-option" value="{{ $language->id }}">
                            <span>{{ $language->flag }} {{ $language->name }}</span>
                        </label>
                    @endif
                @endforeach
            </div>
            <div class="mt-4 flex items-center gap-2">
                <x-forms.button-small class="js-discard">Discard</x-forms.button-small>
            </div>
        @else
            {{-- The Term sits in a block of its own: nothing here is strikeable, and
                 striking never rewrites it. --}}
            <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-white/10 pb-3">
                <div class="min-w-0">
                    <span class="js-term text-2xl font-medium break-words">{{ $proposal->term }}</span>
                </div>

                {{-- The detected language, editable: staging is where a wrong detection
                     gets corrected, which sends the proposal back through CALL 1. --}}
                <select class="js-language rounded-lg bg-white/10 border border-white/10 px-3 py-1 text-sm">
                    @foreach($targetLanguages as $language)
                        <option value="{{ $language->id }}" @selected($proposal->language_id === $language->id)>
                            {{ $language->flag }} {{ $language->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- The Context is editable on every proposal: saving it re-runs CALL 1, so the
                 words and their translations follow the sense it names. --}}
            <input type="text" class="js-context mt-3 w-full rounded-lg bg-white/5 border border-white/10 px-3 py-1 text-sm"
                   value="{{ $proposal->context }}" placeholder="Add context (where you met it, or which sense you mean)" maxlength="250">

            {{-- The sense picker: an ambiguous lone word captured without a Context. Picking a
                 sense writes it as the Context, which re-runs CALL 1 in that sense. --}}
            @if($proposal->senses)
                <div class="mt-3 space-y-1 text-sm">
                    <p class="text-white/60">Which sense do you mean?</p>
                    @foreach($proposal->senses as $sense)
                        <label class="flex items-baseline gap-2 cursor-pointer">
                            <input type="radio" name="sense-{{ $proposal->id }}" class="js-sense"
                                   data-context="{{ $proposal->term }} ({{ $sense['part_of_speech'] }}): {{ $sense['gloss'] }}">
                            <span>
                                <span class="text-xs text-white/40">{{ $sense['part_of_speech'] }}</span>
                                {{ $sense['gloss'] }}
                                <span class="text-white/50">— {{ $sense['translation'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif

            {{-- An identical Term is saved as a second card only with a Context of its own, or
                 by merging the old card into this one. --}}
            @foreach($duplicates as $duplicate)
                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-white/60">
                    <span>
                        You already have
                        <a href="/cards/{{ $duplicate->id }}" class="font-bold text-white hover:underline">{{ $duplicate->term }}</a>.
                        @if($blocked->contains($duplicate))
                            Add a Context to keep both, merge it into this one, or regenerate it instead.
                        @endif
                    </span>
                    @include('staging._merge-toggle', ['card' => $duplicate])
                </div>
            @endforeach

            {{-- The chip tray. New words are strikeable: ✕ records the word as known, so it
                 is never proposed again. Words already in the base and known words sit aside
                 with nothing to decide — an already-present one expands to the cards using
                 it, a known one is tapped to un-know it. --}}
            @php
                $groups = $proposal->baseWords->groupBy(fn ($word) => $proposal->groupOf($word, $alreadyInBase, $knownWords));
            @endphp
            <div class="mt-3 flex flex-wrap gap-2">
                @forelse($groups->get(\App\Models\Proposal::GROUP_NEW, []) as $word)
                    <span class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-1 text-sm">
                        <span>
                            {{ $word->displayForm() }}
                            <span class="text-xs text-white/40">{{ $word->part_of_speech }}</span>
                        </span>
                        <button type="button" class="js-known text-white/50 hover:text-white"
                                data-word-id="{{ $word->id }}" data-known="1" title="I know this word">✕</button>
                    </span>
                @empty
                    <p class="text-sm text-white/40">No new words for the vocabulary base.</p>
                @endforelse
            </div>

            @if($groups->has(\App\Models\Proposal::GROUP_PRESENT) || $groups->has(\App\Models\Proposal::GROUP_KNOWN))
                <div class="mt-2 flex flex-wrap gap-2 text-xs text-white/50">
                    @foreach($groups->get(\App\Models\Proposal::GROUP_PRESENT, []) as $word)
                        @php $present = $alreadyInBase->get($proposal->presenceKeyFor($word)); @endphp
                        <div class="js-chip-wrap">
                            <button type="button" class="js-chip-body rounded-lg border border-white/5 px-2 py-0.5 text-left">
                                {{ $word->displayForm() }}
                                <span class="text-orange-400">already in base</span>
                            </button>
                            <div class="js-chip-detail hidden mt-1 pl-2">
                                @forelse($present->cards as $used)
                                    <a href="/cards/{{ $used->id }}" class="block hover:text-white">{{ $used->term }}</a>
                                @empty
                                    <span>in the base, not used by any card yet</span>
                                @endforelse
                            </div>
                        </div>
                    @endforeach

                    @foreach($groups->get(\App\Models\Proposal::GROUP_KNOWN, []) as $word)
                        <button type="button" class="js-known rounded-lg border border-white/5 px-2 py-0.5 hover:text-white"
                                data-word-id="{{ $word->id }}" data-known="0" title="Tap to un-know">
                            {{ $word->displayForm() }}
                            <span class="text-white/30">known</span>
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- Fixed expressions, learnt as wholes. A new one is struck for this proposal
                 only; one already in the expression base has nothing to decide and is linked. --}}
            @if($proposal->fixedExpressions->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($proposal->fixedExpressions as $expression)
                        @if($expressionsInBase->has($proposal->expressionKeyFor($expression)))
                            <span class="rounded-lg border border-white/5 px-2 py-0.5 text-xs text-white/50">
                                {{ $expression->form }}
                                <span class="text-orange-400">already in expression base</span>
                            </span>
                        @else
                            <span @class([
                                'inline-flex items-center gap-2 rounded-lg border px-3 py-1 text-sm',
                                'border-blue-500/30 bg-blue-500/10' => ! $expression->struck,
                                'border-white/5 text-white/30 line-through' => $expression->struck,
                            ])>
                                <span>
                                    {{ $expression->form }}
                                    <span class="text-xs text-white/40">expression</span>
                                </span>
                                <button type="button" class="js-strike-expression text-white/50 hover:text-white"
                                        data-expression-id="{{ $expression->id }}" data-struck="{{ $expression->struck ? 0 : 1 }}">{{ $expression->struck ? '＋' : '✕' }}</button>
                            </span>
                        @endif
                    @endforeach
                </div>
            @endif

            {{-- Related cards: what this card overlaps with, any of which can be merged away
                 on approval (wordboxes carried over). --}}
            @php $related = $proposal->relatedCards($alreadyInBase); @endphp
            @if($related->isNotEmpty())
                <div class="mt-3 space-y-1 text-sm">
                    <p class="text-white/60">Related cards</p>
                    @foreach($related as $item)
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="/cards/{{ $item['card']->id }}" class="hover:underline">{{ $item['card']->term }}</a>
                            @if($item['redundant'])
                                <span class="text-xs text-orange-400">made redundant by this card</span>
                            @endif
                            @include('staging._merge-toggle', ['card' => $item['card']])
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="mt-4 flex items-center gap-2">
                <x-forms.button class="js-approve" :disabled="$proposal->isApprovable() ? 'false' : 'true'">Approve</x-forms.button>
                @if($blocked->isNotEmpty())
                    <x-forms.button class="js-regenerate" data-card-id="{{ $blocked->first()->id }}">Regenerate that card</x-forms.button>
                @endif
                <x-forms.button-small class="js-discard">Discard</x-forms.button-small>
            </div>
        @endif
    </div>
@empty
    <p class="js-empty text-center text-white/40 py-10">Nothing in staging. Capture a term above.</p>
@endforelse
