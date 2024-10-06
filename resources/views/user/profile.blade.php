@props(["themes"])
<x-html-layout>
    <div>
        <p class="text-5xl">{{Auth::user()->username}}</p>
{{--        <p class="text-lg">You can generate {{Auth::user()->currency_amount}} more cards.</p>--}}
        <p class="text-lg">The app is in beta. You can generate unlimited number of cards.</p>
    </div>
    <div class="mt-3">
        <a href="/profile/edit">
            <x-forms.button>Edit profile</x-forms.button>
        </a>
    </div>

    <br>
    <x-forms.divider></x-forms.divider>

    <div class="space-y-4 flex flex-col">
        @if(count($themes) === 0)
            <p>Here will be list of theme you defined. Each new card will be assigned to appropriate theme, if possible.</p>
        @else

                <table class="table-auto text-left text-white max-w-2xl divide-gray-700 bg-white/5">
                    <thead class="text-white">
                    <tr>
                        <th class="px-4 py-3 text-2xl">Defined themes</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700">
                    @foreach($themes as $theme)
                        <tr class="hover:bg-white/10">
                            <td class="px-4 py-2">{{ $theme['name'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>


        @endif
        <a href="/themes/manage">
            <x-forms.button>Edit themes</x-forms.button>
        </a>
    </div>
    <br>
    <a href="/">
        <x-forms.button-small>Back to main page</x-forms.button-small>
    </a>


</x-html-layout>
