import 'jquery';
import 'jquery-ui/ui/widgets/autocomplete.js';
import 'jquery-validation';
import 'jquery.cookie';
import 'chart.js';
import 'humanize-duration';

require('./bootstrap');
require('./laravel');
require('./charthelper');
require('./front');
window.humanizeDuration = require('humanize-duration');
