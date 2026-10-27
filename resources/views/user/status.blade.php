<x-html-layout>
    <x-page-heading>System status</x-page-heading>

    <div class="mx-auto max-w-2xl space-y-6">
        <x-panel>
            <h2 class="text-xl font-bold mb-2">OpenAI</h2>
            <p id="openaiStatus" class="text-white/50">Checking…</p>
            <p class="mt-2 text-xs text-white/40">Asks OpenAI for the chat model's own record, which costs no tokens.</p>
        </x-panel>

        <x-panel>
            <h2 class="text-xl font-bold mb-2">Lexicon</h2>
            <p class="text-sm text-white/50 mb-4">
                A downloaded dictionary that settles a word's gender and verb form without asking the AI.
                Only Swedish has one (<code>php artisan lexicon:import-saldo --download</code>).
            </p>

            @if($languages->isEmpty())
                <p class="text-white/50">You have no languages set up yet.</p>
            @else
                <ul class="space-y-2">
                    @foreach($languages as $language)
                        @php $loaded = $lexicon->get($language->code); @endphp
                        <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 rounded-lg border border-white/10 bg-white/5 px-4 py-3">
                            <span class="font-bold">{{ $language->flag }} {{ $language->name }}</span>
                            @if($loaded)
                                <span class="text-sm text-green-400">
                                    Ready — {{ number_format($loaded['nouns']) }} nouns ({{ number_format($loaded['genders']) }} with a gender),
                                    {{ number_format($loaded['verbs']) }} verbs ({{ number_format($loaded['forms']) }} with a dictionary form)
                                </span>
                            @else
                                <span class="text-sm text-white/40">Not loaded — the AI answers grammar for this language</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-panel>

        <a href="/profile" class="inline-block max-lg:py-2">
            <x-forms.button-small>Back to account</x-forms.button-small>
        </a>
    </div>

    <script>
        $(function () {
            $.getJSON('/profile/status/openai').done(function (status) {
                $('#openaiStatus')
                    .text((status.ok ? '✓ ' : '✗ ') + status.message)
                    .toggleClass('text-green-400', status.ok)
                    .toggleClass('text-red-400', ! status.ok)
                    .removeClass('text-white/50');
            }).fail(function () {
                $('#openaiStatus').text('✗ The check itself failed.').addClass('text-red-400').removeClass('text-white/50');
            });
        });
    </script>
</x-html-layout>
