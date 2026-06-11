import './bootstrap';
import 'jquery-ui-dist/jquery-ui.js';
import 'jquery-validation';
import { Chart, registerables } from 'chart.js';
import humanizeDuration from 'humanize-duration';

Chart.register(...registerables);
window.Chart = Chart;
window.humanizeDuration = humanizeDuration;

import './laravel';
import './charthelper';
import './front';
