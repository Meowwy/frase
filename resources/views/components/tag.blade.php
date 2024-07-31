@props(['size' => 'base'])
@php
    $classes = 'bg-white/10 hover:bg-white/25 rounded-xl transition-colors duration-300';
        if($size == 'base'){
            $classes .= 'px-3 py-1 text-sm font-bold';
        } elseif ($size == 'small'){
            $classes .= 'px-3 py-1 text-2xs';
        }
@endphp
<a href="/tags/{{strtolower('tag')}}" class="{{$classes}}">{{'tag'}}</a>
