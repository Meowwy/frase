@props(['number' => 0, 'text' => ''])

<div {{$attributes->merge(['class' => 'flex flex-col justify-center items-center p-4 rounded-lg w-24 h-24 bg-white/5'])}}>
    <div class="text-xl font-bold">
        <p id="{{$text}}">{{$number}}</p>
    </div>
    @if($text)
        <div class="text-sm mt-1">
            <p>{{$text}}</p>
        </div>
    @endif
</div>
