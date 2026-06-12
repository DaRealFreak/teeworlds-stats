@props(['player', 'size' => 50])
@php
    // resolve once: local skin > DDNet-DB fetch > default tee (the renderer follows that order)
    $tee = \App\Utility\TeeSkin::describe($player->skin, $player->color_body, $player->color_feet, $player->skin_parts);
@endphp
@if ($tee)
    <canvas {{ $attributes->class(['player-tee']) }} width="{{ $size }}" height="{{ $size }}" data-tee='@json($tee)'></canvas>
@else
    {{-- never observed any cosmetics (UDP-only sighting) — keep the generic icon, sized to
         match the canvas branch so it doesn't balloon to the placeholder's native size where
         no fixed-size .profile/.avatar frame constrains it (e.g. the mod/map detail toplists) --}}
    <img src="{{ asset('images/user.png') }}" alt="{{ $player->name }}" width="{{ $size }}" height="{{ $size }}" {{ $attributes->class(['img-fluid']) }}>
@endif
