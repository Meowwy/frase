@props(['heading'=>'heading','text'=>'Sample text.'])
<x-panel outline="orange" class="p-4">
    <div class="flex flex-col h-full">
        <div
            class="group-hover:text-orange-800 text-2xl font-bold transition-colors duration-100 self-start">
            <p>{{$heading}}</p>
        </div>
        <div class="text-lg mt-3">
            <p>{{$text}}</p>
        </div>
    </div>
</x-panel>
