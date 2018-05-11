<?php

namespace App\Utility;

class ChartUtility
{

    /**
     * function to apply the amount and displayOthers limitation and sort the
     * results by value
     *
     * @param $results
     * @param $amount
     * @param $displayOthers
     */
    public static function applyLimits(&$results, $amount, $displayOthers)
    {
        if ($amount && count($results) > $amount) {
            arsort($results, true);
            $i = 0;
            foreach ($results as $map => $times) {
                if ($i >= $amount) {
                    if (isset($results['others'])) {
                        $results['others'] += $times;
                    } else {
                        $results['others'] = $times;
                    }
                    unset($results[$map]);
                }
                $i++;
            }
            if (!$displayOthers) {
                unset($results['others']);
            }
        }
        arsort($results, true);
    }
}