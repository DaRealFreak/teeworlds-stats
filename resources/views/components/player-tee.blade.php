@props(['player', 'size' => 50])
@php
    // resolve once: local skin > DDNet-DB fetch > default tee (the renderer follows that order)
    $tee = \App\Utility\TeeSkin::describe($player->skin, $player->color_body, $player->color_feet, $player->skin_parts);
@endphp
@if ($tee)
    <canvas {{ $attributes->class(['player-tee']) }} width="{{ $size }}" height="{{ $size }}" data-tee='@json($tee)'></canvas>
@else
    {{-- never observed any cosmetics (UDP-only sighting) — keep the generic icon --}}
    <img src="{{ asset('images/user.png') }}" alt="{{ $player->name }}" {{ $attributes->class(['img-fluid']) }}>
@endif
