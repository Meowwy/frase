@props(["disabled" => "false"])
<button @if($disabled !== "false") disabled @endif
    {{ $attributes(['class' => 'bg-blue-800 hover:bg-blue-600 transition-colors duration-100 rounded py-2 px-6 font-bold']) }}>{{ $slot }}</button>
