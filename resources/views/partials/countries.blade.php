{{-- Ranked country breakdown shared by the general statistics and server detail
     pages. Expects $countryStats (see ChartUtility::rankCountries) and $canvasId
     for the accompanying doughnut. --}}
<div class="chart-compact">
    <canvas id="{{ $canvasId }}"></canvas>
</div>
<ul class="country-rank">
    @foreach ($countryStats['countries'] as $country)
        <li>
            <span class="fi fi-{{ $country['code'] }}"></span>
            <span class="country-rank__name">{{ $country['name'] }}</span>
            <span class="country-rank__bar">
                <span style="width: {{ $countryStats['max'] > 0 ? round($country['count'] / $countryStats['max'] * 100, 1) : 0 }}%"></span>
            </span>
            <span class="country-rank__count">{{ number_format($country['count']) }}</span>
        </li>
    @endforeach
    @if ($countryStats['unknown'] > 0)
        <li>
            <i class="fa fa-globe country-rank__globe"></i>
            <span class="country-rank__name country-rank__muted">Other &amp; unknown</span>
            <span class="country-rank__bar">
                <span style="width: {{ $countryStats['max'] > 0 ? round($countryStats['unknown'] / $countryStats['max'] * 100, 1) : 0 }}%; background: #4a4d53"></span>
            </span>
            <span class="country-rank__count country-rank__muted">{{ number_format($countryStats['unknown']) }}</span>
        </li>
    @endif
</ul>
