<x-html-layout>
    <div class="mb-2">
        <x-section-heading>reorder wordboxes</x-section-heading>
    </div>
    <p class="text-sm text-white/60 mb-6">
        Drag to set the order wordboxes appear in when saving a word. Those that fit show on the line
        (left to right); the rest move into the "More" dropdown, top to bottom.
    </p>

    @if($languages->isEmpty())
        <p class="text-white/50">You have no languages set up yet.</p>
    @else
        <div class="space-y-8">
            @foreach($languages as $lang)
                @php $boxes = $wordboxesByLanguage[$lang->id] ?? collect(); @endphp
                <div>
                    <h3 class="font-bold mb-3">{{ $lang->flag }} {{ $lang->name }}</h3>
                    @if($boxes->isEmpty())
                        <p class="text-sm text-white/40">No wordboxes in this language.</p>
                    @else
                        <ul class="sortable space-y-2" data-language-id="{{ $lang->id }}">
                            @foreach($boxes as $box)
                                <li data-id="{{ $box->id }}"
                                    class="flex items-center gap-3 bg-white/5 border border-white/10 px-4 py-3 rounded-lg cursor-grab active:cursor-grabbing hover:bg-white/10 transition-colors">
                                    <span class="text-white/30 select-none">⠿</span>
                                    <span>{{ $box->name }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="flex items-center gap-3 mt-8">
            <x-forms.button id="saveOrder">Save order</x-forms.button>
            <span id="savedHint" class="hidden text-sm text-green-400">Saved.</span>
        </div>
    @endif

    <div class="mt-6">
        <a href="/profile/edit">
            <x-forms.button-small>&larr; Back to settings</x-forms.button-small>
        </a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
    <script>
        $(document).ready(function () {
            document.querySelectorAll('.sortable').forEach(function (el) {
                new Sortable(el, { animation: 150, ghostClass: 'opacity-40' });
            });

            $('#saveOrder').on('click', function () {
                const order = {};
                document.querySelectorAll('.sortable').forEach(function (el) {
                    const langId = el.dataset.languageId;
                    order[langId] = Array.from(el.querySelectorAll('li')).map(li => li.dataset.id);
                });

                $.ajax({
                    url: "{{ route('wordboxes.order.update') }}",
                    method: 'POST',
                    data: { _token: '{{ csrf_token() }}', order: order },
                    success: function () {
                        $('#savedHint').removeClass('hidden');
                        toastr.success('Wordbox order saved.');
                    },
                    error: function () {
                        toastr.error('Could not save the order.');
                    }
                });
            });
        });
    </script>
</x-html-layout>
