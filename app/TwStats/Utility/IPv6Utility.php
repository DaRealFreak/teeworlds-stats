<?php
/**
 * taken from http://php.net/manual/en/function.gethostbyname.php#70936
 * to add option to resolve IPv6 address from host name in PHP
 */

namespace App\TwStats\Utility;


class IPv6Utility
{
    /**
     * get AAAA record for $host
     * if $try_a is true, if AAAA fails, it tries for A
     * the first match found is returned
     * otherwise returns false
     *
     * @param $host
     * @param bool $try_a
     * @return bool|mixed
     */
    public static function gethostbyname6($host, $try_a = false)
    {
        $dns = self::gethostbynamel6($host, $try_a);
        if ($dns == false) {
            return false;
        } else {
            return $dns[0];
        }
    }

    /**
     * get AAAA records for $host,
     * if $try_a is true, if AAAA fails, it tries for A
     * results are returned in an array of ips found matching type
     * otherwise returns false
     *
     * @param $host
     * @param bool $try_a
     * @return array|bool
     */
    public static function gethostbynamel6($host, $try_a = false)
    {
        $dns6 = dns_get_record($host, DNS_AAAA);
        if ($try_a == true) {
            $dns4 = dns_get_record($host, DNS_A);
            $dns = array_merge($dns4, $dns6);
        } else {
            $dns = $dns6;
        }
        $ip6 = [];
        $ip4 = [];
        foreach ($dns as $record) {
            if ($record["type"] == "A") {
                $ip4[] = $record["ip"];
            }
            if ($record["type"] == "AAAA") {
                $ip6[] = $record["ipv6"];
            }
        }
        if (count($ip6) < 1) {
            if ($try_a == true) {
                if (count($ip4) < 1) {
                    return false;
                } else {
                    return $ip4;
                }
            } else {
                return false;
            }
        } else {
            return $ip6;
        }
    }
}