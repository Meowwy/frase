{{-- `numberId` goes on the number itself, so a script can update it. --}}
@props(['number' => 0, 'text' => '', 'numberId' => null])

<div {{$attributes->merge(['class' => 'flex flex-col justify-center items-center p-4 rounded-lg w-24 h-24 bg-white/5'])}}>
    <div class="text-xl font-bold">
        <p @if($numberId) id="{{ $numberId }}" @endif>{{$number}}</p>
    </div>
    @if($text)
        <div class="text-sm mt-1">
            <p>{{$text}}</p>
        </div>
    @endif
</div>
