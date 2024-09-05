<x-html-layout>
    @if(count($themes) > 0)
        <div>
            <div class="flex">
                <x-forms.form action="/cards/themeFilter" method="POST" id="themeForm">
                    <x-forms.select label="Select Theme" name="themeSelect" id="themeSelect">
                        <x-forms.option value="All themes">All themes</x-forms.option>
                        @foreach($themes as $theme)
                            @if(isset($selectedTheme))
                                @if($selectedTheme === $theme['name'])
                                    <x-forms.option selected value="{{$theme['name']}}">{{$theme['name']}}</x-forms.option>
                                    @continue
                                @endif
                            @endif
                            <x-forms.option value="{{$theme['name']}}">{{$theme['name']}}</x-forms.option>
                        @endforeach
                    </x-forms.select>
                    <x-forms.button-confirm type="submit">Update</x-forms.button-confirm>
                </x-forms.form>

            </div>

        </div>
    @endif


    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-700 bg-white/5">
            <thead>
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Term</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Definition
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Theme</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-700">
            @foreach($cards as $card)
                @if(request('theme') && $card->theme)
                    @php
                    //$adjustedTheme = str_replace("%20", " ", $card->theme->name);
                    @endphp
                    @if(request('theme') !== $card->theme->name)
                        @continue
                    @endif
                @elseif(request('theme') && !$card->theme)
                    @continue
                @endif

                <tr class="hover:bg-white/10">
                    <td class="px-6 py-2 whitespace-nowrap text-sm font-medium text-white">
                        <a href="/cards/{{$card->id}}">
                            {{$card->phrase}}
                        </a>
                    </td>
                    <td class="px-6 py-2 whitespace-nowrap text-sm text-gray-300">{{$card->definition}}</td>
                    <td class="px-6 py-2 whitespace-nowrap text-sm text-gray-300">{{$card->theme ? $card->theme->name : 'no theme'}}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="mt-4">
            {{ $cards->links() }}
        </div>
    </div>

        {{--<script>
            function submitForm() {
                const form = document.getElementById('themeForm');
                const selectedTheme = document.getElementById('themeSelect').value;

                // Update the form action URL with the selected theme
                form.action = `/cards/theme/${encodeURIComponent(selectedTheme)}`;

                // Submit the form
                form.submit();
            }
        </script>--}}
</x-html-layout>
