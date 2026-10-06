{{-- Mark an existing card to be removed when this proposal is approved, or unmark it. A
     switch, not a button: nothing happens to the card until approval. --}}
@php $marked = $proposal->isMarkedForMerge($card); @endphp
<button type="button" role="switch" aria-checked="{{ $marked ? 'true' : 'false' }}"
        class="js-merge inline-flex items-center gap-2 text-xs text-white/50 hover:text-white"
        data-card-id="{{ $card->id }}" data-merge="{{ $marked ? 0 : 1 }}"
        title="Remove this card when approving, keeping its wordboxes">
    <span @class(['relative inline-block h-4 w-7 rounded-full transition-colors', 'bg-red-500/70' => $marked, 'bg-white/20' => ! $marked])>
        <span @class(['absolute top-0.5 h-3 w-3 rounded-full bg-white transition-all', 'left-3.5' => $marked, 'left-0.5' => ! $marked])></span>
    </span>
    merge into this card
</button>
