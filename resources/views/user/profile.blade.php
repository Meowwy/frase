@props(["themes"])
<x-html-layout>
    <div>
        <p class="text-5xl">{{Auth::user()->username}}</p>
{{--        <p class="text-lg">You can generate {{Auth::user()->currency_amount}} more cards.</p>--}}
        <p class="text-lg">The app is for invited people only. You can generate unlimited number of cards.</p>
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
            <p>Here will be list of theme. Each new card will be assigned appropriate theme, or it will create a new theme.</p>
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
    <x-forms.divider></x-forms.divider>

    <div class="space-y-3 flex flex-col">
        <p class="text-2xl">Created wordboxes</p>
        @php $langById = $languages->keyBy('id'); @endphp
        @if($wordboxes->isEmpty())
            <p class="text-sm text-white/50">You haven't created any wordboxes yet.</p>
        @else
            <ul class="space-y-1 max-w-2xl">
                @foreach($wordboxes as $box)
                    <li class="flex items-center justify-between bg-white/5 border border-white/10 px-3 py-2 rounded-lg">
                        <span>{{ $box->name }}</span>
                        <span class="text-xs text-white/40">{{ optional($langById->get($box->language_id))->flag }}</span>
                    </li>
                @endforeach
            </ul>
            @if($wordboxCount > 5)
                <p class="text-xs text-white/40">+ {{ $wordboxCount - 5 }} more</p>
            @endif
        @endif
        <a href="{{ route('wordboxes.order') }}">
            <x-forms.button>Reorder wordboxes</x-forms.button>
        </a>
    </div>

</x-html-layout>
