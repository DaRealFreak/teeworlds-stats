<script>
    document.addEventListener('DOMContentLoaded', function () {

        ChartHelper.lineChart(document.getElementById('onlineLineChartDays'),
            ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
            blade({!! json_encode($entity->chartOnlineDays()) !!})
        );

        ChartHelper.lineChart(document.getElementById('onlineLineChartHours'),
            ["12 AM", "1 AM", "2 AM", "3 AM", "4 AM", "5 AM", "6 AM", "7 AM", "8 AM", "9 AM", "10 AM", "11 AM", "12 PM", "13 PM", "14 PM", "15 PM", "16 PM", "17 PM", "18 PM", "19 PM", "20 PM", "21 PM", "22 PM", "23 PM"],
            blade({!! json_encode($entity->chartOnlineHours()) !!})
        );

        @if (count($crossChart))
        let crossChart = ChartHelper.pieChart(document.getElementById('crossChart'),
            blade({!! json_encode(array_keys($crossChart)) !!}),
            blade({!! json_encode(array_values($crossChart)) !!})
        );
        ChartHelper.chartColors(crossChart, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
        @endif

    });
</script>
