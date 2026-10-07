@props(["disabled" => "false"])
<button @if($disabled !== "false") disabled @endif
    {{ $attributes(['class' => 'bg-blue-800 hover:bg-blue-600 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-blue-800 transition-colors duration-100 rounded py-2 px-6 font-bold']) }}>{{ $slot }}</button>
