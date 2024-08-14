@props(['outline' => 'blue'])

@php
if($outline == 'blue'){
    $hover = 'hover:border-blue-800 p-4';
} else if($outline == 'orange'){
    $hover = 'hover:border-orange-800 p-2';
}

@endphp
<div {{$attributes->merge(['class' => "bg-white/5 rounded-xl flex border border-transparent $hover group transition-colors duration-300"])}}>
    {{$slot}}
</div>
