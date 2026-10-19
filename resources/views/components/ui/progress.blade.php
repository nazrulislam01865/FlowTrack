@props(['value' => 0, 'height' => null])
<div class="progress" @if($height) style="height:{{ $height }}px" @endif><span style="width:{{ max(0, min(100, (int)$value)) }}%"></span></div>
