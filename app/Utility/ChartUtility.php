<?php

namespace App\Utility;

class ChartUtility
{

    /**
<<<<<<< HEAD
=======
     * @param Collection $collection
     * @param string $keyAttribute
     * @param null|string $valueAttribute
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public static function chartValues(Collection $collection, string $keyAttribute, ?string $valueAttribute, int $amount = 16, bool $displayOthers = True)
    {
        $results = [];
        foreach ($collection as $item) {
            $mapName = $item->getAttribute($keyAttribute);

            if ($valueAttribute) {
                $value = $item->getAttribute($valueAttribute);
            } else {
                $value = 1;
            }
            if (array_key_exists($mapName, $results)) {
                $results[$mapName] += $value;
            } else {
                $results[$mapName] = $value;
            }
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
    }

    /**
>>>>>>> a78c4ee... [!git add app/ routes/][TASK] rename times to minutes, extract maps and mods to unified table connected to models with record models
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