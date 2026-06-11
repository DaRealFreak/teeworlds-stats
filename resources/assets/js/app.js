import './bootstrap';
import 'jquery-ui/ui/widget.js';
import 'jquery-ui/ui/position.js';
import 'jquery-ui/ui/keycode.js';
import 'jquery-ui/ui/unique-id.js';
import 'jquery-ui/ui/widgets/menu.js';
import 'jquery-ui/ui/widgets/autocomplete.js';
import 'jquery-validation';
import { Chart, registerables } from 'chart.js';
import humanizeDuration from 'humanize-duration';

Chart.register(...registerables);
window.Chart = Chart;
window.humanizeDuration = humanizeDuration;

import './laravel';
import './charthelper';
import './front';
