{{-- Mark an existing card to be removed when this proposal is approved, or unmark it. --}}
@php $marked = $proposal->isMarkedForMerge($card); @endphp
<button type="button" @class([
    'js-merge rounded-lg border px-2 py-0.5 text-xs',
    'border-red-500/40 bg-red-500/10 text-red-300' => $marked,
    'border-white/10 text-white/50 hover:text-white' => ! $marked,
]) data-card-id="{{ $card->id }}" data-merge="{{ $marked ? 0 : 1 }}"
   title="{{ $marked ? 'Tap to keep this card' : 'Remove this card when approving, keeping its wordboxes' }}">
    {{ $marked ? 'Merging' : 'Merge' }}
</button>
