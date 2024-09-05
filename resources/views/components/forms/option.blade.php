@props(['value' => '-1'])
<option value="{{$value}}" {{$attributes->merge(['class'=>'bg-black/50 text-white px-4 py-2'])}}>{{$slot}}</option>
