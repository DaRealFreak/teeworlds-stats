<?php

namespace App\Utility;

use App\TwStats\Utility\Countries;
use Khill\Duration\Duration;

class ChartUtility
{

    /**
     * turn a map of [country name => player count] into a ranked breakdown for the
     * frontend: the top $amount real countries (each with a flag-icons code) plus a
     * single folded bucket for players without a country and the long tail
     *
     * @param array<string, int> $counts
     * @param int $amount
     * @return array{countries: array<int, array{name: string, code: string, count: int}>, unknown: int, max: int, total: int}
     */
    public static function rankCountries(array $counts, int $amount = 8): array
    {
        arsort($counts);

        $countries = [];
        $unknown = 0;

        foreach ($counts as $name => $count) {
            $name = (string)$name;
            $count = (int)$count;
            $flagCode = Countries::getFlagCode($name);

            // "none", the applyLimits "others" bucket and anything without a real flag
            // share the muted unknown row
            if ($name === 'none' || $name === 'others' || $flagCode === null) {
                $unknown += $count;
                continue;
            }

            $countries[] = ['name' => $name, 'code' => $flagCode, 'count' => $count];
        }

        $top = array_slice($countries, 0, $amount);
        foreach (array_slice($countries, $amount) as $tail) {
            $unknown += $tail['count'];
        }

        return [
            'countries' => $top,
            'unknown' => $unknown,
            'max' => $top[0]['count'] ?? 1,
            'total' => array_sum(array_column($countries, 'count')) + $unknown,
        ];
    }

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

    /**
     * function to humanize the tracked minutes into a human time(h-m-s or if needed even d-h-m-s etc)
     *
     * @param $minutes
     * @return string
     */
    public static function humanizeDuration($minutes)
    {
        return (new Duration($minutes * 60))->humanize();
    }
}