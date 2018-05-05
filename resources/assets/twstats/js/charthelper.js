$(function () {
    window.ChartHelper = class ChartHelper {

        static chartColors(chart, gradient) {
            /*Gradients
              The keys are percentage and the values are the color in a rgba format.
              You can have as many "color stops" (%) as you like.
              0% and 100% is not optional.*/

            //Get a sorted array of the gradient keys
            let gradientKeys = Object.keys(gradient);
            gradientKeys.sort(function (a, b) {
                return +a - +b;
            });

            //Find datasets and length
            let chartType = chart.config.type;
            let datasets;
            let setsCount;
            switch (chartType) {
                case "pie":
                case "doughnut":
                    datasets = chart.config.data.datasets[0];
                    setsCount = datasets.data.length;
                    break;
                case "bar":
                case "line":
                    datasets = chart.config.data.datasets;
                    setsCount = datasets.length;
                    break;
            }

            //Calculate colors
            let chartColors = [];
            for (let i = 0; i < setsCount; i++) {
                let gradientIndex = (i + 1) * (100 / (setsCount + 1)); //Find where to get a color from the gradient
                for (let j = 0; j < gradientKeys.length; j++) {
                    let gradientKey = gradientKeys[j];
                    if (gradientIndex === +gradientKey) { //Exact match with a gradient key - just get that color
                        chartColors[i] = 'rgba(' + gradient[gradientKey].toString() + ')';
                        break;
                    } else if (gradientIndex < +gradientKey) { //It's somewhere between this gradient key and the previous
                        let prevKey = gradientKeys[j - 1];
                        let gradientPartIndex = (gradientIndex - prevKey) / (gradientKey - prevKey); //Calculate where
                        let color = [];
                        for (let k = 0; k < 4; k++) { //Loop through Red, Green, Blue and Alpha and calculate the correct color and opacity
                            color[k] = gradient[prevKey][k] - ((gradient[prevKey][k] - gradient[gradientKey][k]) * gradientPartIndex);
                            if (k < 3) color[k] = Math.round(color[k]);
                        }
                        chartColors[i] = 'rgba(' + color.toString() + ')';
                        break;
                    }
                }
            }

            //Copy colors to the chart
            for (let i = 0; i < setsCount; i++) {
                switch (chartType) {
                    case "pie":
                    case "doughnut":
                        if (!datasets.backgroundColor) datasets.backgroundColor = [];
                        datasets.backgroundColor[i] = chartColors[i];
                        if (!datasets.borderColor) datasets.borderColor = [];
                        datasets.borderColor[i] = "rgba(255,255,255,1)";
                        break;
                    case "bar":
                        datasets[i].backgroundColor = chartColors[i];
                        datasets[i].borderColor = "rgba(255,255,255,0)";
                        break;
                    case "line":
                        datasets[i].borderColor = chartColors[i];
                        datasets[i].backgroundColor = "rgba(255,255,255,0)";
                        break;
                }
            }

            //Update the chart to show the new colors
            chart.update();
        }
    };
});