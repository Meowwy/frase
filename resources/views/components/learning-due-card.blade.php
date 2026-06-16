@props(['language'])
{{-- Dashboard "Review due cards" card: solid orange to draw attention, no hover,
     links straight into a due-scoped learning set for the language. --}}
<div class="m-2 w-72 flex flex-col gap-4 rounded-xl bg-orange-800 p-5">
    <div class="flex flex-col gap-1 px-1">
        <p class="text-xl font-bold">{{ $language->flag }} {{ $language->name }}</p>
        <p class="text-3xl font-bold text-white">{{ $language->due_count }} cards due</p>
    </div>
    <a href="/setLearning?language_id={{ $language->id }}">
        <x-forms.button-small class="!bg-orange-700 !border-orange-700 hover:!bg-orange-700 px-4 py-2 text-white whitespace-nowrap">Review due cards</x-forms.button-small>
    </a>
</div>
