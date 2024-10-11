@props(['name', 'disabled' => "false"])

@php
    $defaults = [
        'type' => 'text',
        'id' => $name,
        'name' => $name,
        'class' => 'bg-white/5 border border-white/10 px-1 py-1',
        'value' => old($name)
    ];
@endphp


    <input
        @if($disabled !== "false") disabled @endif
        {{ $attributes($defaults) }}>
