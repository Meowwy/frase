@props(['label', 'name', 'disabled' => "false"])

@php
    $defaults = [
        'id' => $name,
        'name' => $name,
        'class' => 'rounded-xl bg-white/10 border border-white/10 px-5 py-4 w-full min-h-[100px]',
    ];
@endphp

<x-forms.field :$label :$name>
    <textarea
        @if($disabled !== "false") disabled @endif
        {{ $attributes($defaults) }}>{{ $slot->isEmpty() ? old($name) : $slot }}</textarea>
</x-forms.field>
