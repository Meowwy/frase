{{-- One row per proposal: a skeleton while CALL 1 is still running, the real thing once it
     has landed. Everything the learner can do to a proposal re-renders this whole list from
     the server, so the approvability rules live in PHP only (see Proposal). --}}

@forelse($proposals as $proposal)
    @php
        $pending = in_array($proposal->status, [\App\Models\Proposal::STATUS_PENDING, \App\Models\Proposal::STATUS_PROCESSING], true);
        $failed = $proposal->status === \App\Models\Proposal::STATUS_FAILED;
        $duplicate = $pending || $failed ? null : $proposal->duplicateCard();
    @endphp

    <div class="rounded-xl border border-white/10 bg-white/5 p-4 js-proposal" data-proposal-id="{{ $proposal->id }}">
        @if($pending)
            <div class="flex items-center gap-3">
                <span class="h-4 w-32 animate-pulse rounded bg-white/20"></span>
                <span class="text-sm text-white/40">reading "{{ $proposal->raw_input }}"…</span>
            </div>
        @elseif($failed)
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm text-white/60">
                    Could not read <span class="font-bold text-white">{{ $proposal->raw_input }}</span>. Discard it and try again.
                </p>
                <x-forms.button-small class="js-discard">Discard</x-forms.button-small>
            </div>
        @else
            {{-- The Term sits in a block of its own: nothing here is strikeable, and
                 striking never rewrites it. --}}
            <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-white/10 pb-3">
                <div class="min-w-0">
                    <span class="text-2xl font-medium break-words">{{ $proposal->term }}</span>
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

            @if($duplicate)
                <p class="mt-3 text-sm text-white/60">
                    You already have
                    <a href="/cards/{{ $duplicate->id }}" class="font-bold text-white hover:underline">{{ $duplicate->term }}</a>.
                    Nothing new can be saved for it — regenerate that card's content instead, or discard this.
                </p>
            @endif

            {{-- The chip tray: one per extracted lemma. The ✕ strikes it so it never
                 becomes a base word; a chip already in the base expands to show which
                 cards use it. --}}
            <div class="mt-3 flex flex-wrap gap-2">
                @forelse($proposal->baseWords as $word)
                    @php $present = $alreadyInBase->get($proposal->presenceKeyFor($word)); @endphp
                    <div class="js-chip-wrap">
                        <span @class([
                            'inline-flex items-center gap-2 rounded-lg border px-3 py-1 text-sm',
                            'border-white/10 bg-white/5' => ! $word->struck,
                            'border-white/5 bg-transparent text-white/30 line-through' => $word->struck,
                        ])>
                            {{-- A chip already in the base is a button: tapping it expands
                                 which cards use that word. One that isn't is a plain label,
                                 since there is nothing to expand. --}}
                            @if($present)
                                <button type="button" class="js-chip-body text-left">
                                    {{ $word->displayForm() }}
                                    <span class="text-xs text-white/40">{{ $word->part_of_speech }}</span>
                                    <span class="text-xs text-orange-400">already in base</span>
                                </button>
                            @else
                                <span>
                                    {{ $word->displayForm() }}
                                    <span class="text-xs text-white/40">{{ $word->part_of_speech }}</span>
                                </span>
                            @endif
                            <button type="button" class="js-strike text-white/50 hover:text-white"
                                    data-word-id="{{ $word->id }}" data-struck="{{ $word->struck ? 1 : 0 }}">{{ $word->struck ? '＋' : '✕' }}</button>
                        </span>

                        @if($present)
                            <div class="js-chip-detail hidden mt-1 pl-3 text-xs text-white/50">
                                @forelse($present->cards as $used)
                                    <a href="/cards/{{ $used->id }}" class="block hover:text-white">{{ $used->term }}</a>
                                @empty
                                    <span>in the base, not used by any card yet</span>
                                @endforelse
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-white/40">No words proposed for the vocabulary base.</p>
                @endforelse
            </div>

            <div class="mt-4 flex items-center gap-2">
                @if($duplicate)
                    <x-forms.button class="js-regenerate" data-card-id="{{ $duplicate->id }}">Regenerate that card</x-forms.button>
                @else
                    <x-forms.button class="js-approve" :disabled="$proposal->isApprovable() ? 'false' : 'true'">Approve</x-forms.button>
                @endif
                <x-forms.button-small class="js-discard">Discard</x-forms.button-small>
            </div>
        @endif
    </div>
@empty
    <p class="text-center text-white/40 py-10">Nothing in staging. Capture a term above.</p>
@endforelse
