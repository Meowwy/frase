<x-html-layout>
    <div class="mb-2">
        <x-section-heading>frammenti settings</x-section-heading>
    </div>
    <p class="text-sm text-white/60 mb-6">
        How many words and fixed expressions you haven't practised yet Frammenti introduces each day.
        Every new one is repeated a few times in that day's session, so a lower number means
        shorter sessions.
    </p>

    @if($languages->isEmpty())
        <p class="text-white/50">You have no languages set up yet.</p>
    @else
        <x-forms.form method="post" action="/profile/frammenti">
            @foreach($languages as $language)
                <div class="flex items-center gap-4 max-w-md">
                    <label for="new_per_day_{{ $language->id }}" class="flex-1">{{ $language->flag }} {{ $language->name }}</label>
                    <input type="number" min="0" max="50" id="new_per_day_{{ $language->id }}"
                           name="new_per_day[{{ $language->id }}]" value="{{ $language->pivot->frammenti_new_per_day }}"
                           class="w-24 rounded-xl bg-white/10 border border-white/10 px-4 py-2">
                    <span class="text-sm text-white/50">new per day</span>
                </div>
            @endforeach
            <x-forms.error :error="$errors->first('new_per_day.*')" />

            <div class="flex items-center gap-3">
                <x-forms.button>Save</x-forms.button>
                @if(session('saved'))
                    <span class="text-sm text-green-400">Saved.</span>
                @endif
            </div>
        </x-forms.form>
    @endif
</x-html-layout>
