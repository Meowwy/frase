@forelse($cards as $card)
    <tr class="hover:bg-white/10 cursor-pointer" onclick="window.location='/cards/{{$card->id}}'">
        <td class="px-6 py-2 whitespace-nowrap text-sm font-medium text-white">{{ $card->phrase }}</td>
        <td class="px-6 py-2 whitespace-nowrap text-sm text-gray-300">{{ $card->definition }}</td>
        <td class="px-6 py-2 whitespace-nowrap text-sm text-gray-300">{{ $card->wordbox->first()?->name ?? 'no wordbox' }}</td>
    </tr>
@empty
    <tr>
        <td colspan="3" class="px-6 py-6 text-center text-sm text-gray-400">No terms found.</td>
    </tr>
@endforelse
