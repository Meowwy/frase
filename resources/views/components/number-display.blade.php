{{-- `numberId` goes on the number itself, so a script can update it. --}}
@props(['number' => 0, 'text' => '', 'numberId' => null])

{{-- Below lg a compact pill, number and label on one line. --}}
<div {{$attributes->merge(['class' => 'flex flex-col justify-center items-center p-4 rounded-lg w-24 h-24 bg-white/5 max-lg:h-auto max-lg:w-auto max-lg:flex-row max-lg:gap-1 max-lg:rounded-full max-lg:px-3 max-lg:py-1'])}}>
    <div class="text-xl font-bold max-lg:text-sm">
        <p @if($numberId) id="{{ $numberId }}" @endif>{{$number}}</p>
    </div>
    @if($text)
        <div class="text-sm mt-1 max-lg:mt-0 max-lg:text-xs max-lg:text-white/60">
            <p>{{$text}}</p>
        </div>
    @endif
</div>
