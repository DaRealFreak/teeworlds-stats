<?php

namespace TwStats\Core\Utility;


class PrettyUrl implements SingletonInterface
{
    /**
     * build an uri string from passed page and parameters
     *
     * @param string $class
     * @param array $params
     * @param bool $usePath
     * @return string
     */
    public static function buildUri($class = "", $params = array(), $usePath = true)
    {
        if ($class && !StringUtility::endsWith($class, ".php")) {
            $class = sprintf("%s.php", $class);
        }

        if (!$usePath) {
            $params['p'] = $class;
            $uri = "index.php?" . http_build_query($params);
        } else {
            $uri = $class;
            if (!empty($params)) {
                $uri = $uri . "?" . http_build_query($params);
            }
        }
        return $uri;
    }

    /**
     * build a pretty uri string from passed page and parameters
     *
     * @param string $class
     * @param array $params
     * @param bool $usePath
     * @return string
     */
    public static function buildPrettyUri($class = "", $params = [], $usePath = true)
    {
        $uri = self::buildUri($class, $params, $usePath);
        // ToDo: prettify
        return $uri;
    }
}