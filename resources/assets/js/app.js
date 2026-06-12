import './bootstrap';
import {
    Chart,
    LineController, LineElement, PointElement,
    PieController, DoughnutController, ArcElement,
    RadarController, RadialLinearScale,
    CategoryScale, LinearScale,
    Filler, Tooltip, Legend,
} from 'chart.js';
import humanizeDuration from 'humanize-duration';

// Register only the components the views use (line, pie, doughnut and radar
// charts) instead of the full `registerables` set, to keep the bundle small.
Chart.register(
    LineController, LineElement, PointElement,
    PieController, DoughnutController, ArcElement,
    RadarController, RadialLinearScale,
    CategoryScale, LinearScale,
    Filler, Tooltip, Legend,
);
window.Chart = Chart;
window.humanizeDuration = humanizeDuration;

import './laravel';
import './charthelper';
import './autocomplete';
import './front';
import './serverbrowser';
import './tee';
