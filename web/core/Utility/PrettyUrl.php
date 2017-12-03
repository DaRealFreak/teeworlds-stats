<?php

namespace TwStats\Core\Utility;


use TwStats\Core\Backend\RequestHandler;
use TwStats\Core\General\SingletonInterface;

class PrettyUrl implements SingletonInterface
{
    /**
     * build an uri string from passed page and parameters
     *
     * @param string $class
     * @param array $params
     * @return string
     * @internal param bool $usePath
     */
    public static function buildUri($class = "", $params = array())
    {
        unset($params['uri']);

        $uri = $class;
        if (!empty($params)) {
            $uri = $uri . "?" . http_build_query($params);
        }
        return $uri;
    }

    /**
     * build a pretty uri string from passed page and parameters
     *
     * @param string $class
     * @param array $params
     * @return string
     * @internal param bool $usePath
     */
    public static function buildPrettyUri($class = "", $params = [])
    {
        $orgUri = self::buildUri($class, $params);
        // return base directory if no class or param is set
        if (!$orgUri) {
            return "/";
        }

        // we need a database connection for slug uris so return the
        if (!isset($GLOBALS['DB'])) {
            return $orgUri;
        }

        if ($res = $GLOBALS['DB']->statement('SELECT slug_uri FROM cache_uri WHERE org_uri=? LIMIT 1', [$orgUri])) {
            $slugUri = $res[0]['slug_uri'];
        } else {
            // FixMe: file paths etc are curently getting slugified too
            $tmpSlugUri = self::buildSlugUri($class, $params);
            $slugUri = $tmpSlugUri;

            $i = 1;
            while ($GLOBALS['DB']->statement('SELECT uid FROM cache_uri WHERE slug_uri=? LIMIT 1', [$slugUri])) {
                $slugUri = $tmpSlugUri . "-" . (string)$i;
            }

            $GLOBALS['DB']->sqlInsert(
                [
                    "org_uri" => $orgUri,
                    "slug_uri" => $slugUri
                ],
                "cache_uri"
            );
        }
        return $slugUri;
    }

    /**
     * slugify the class and values of the query parts of the uri
     *
     * @param $class
     * @param $params
     * @return string
     */
    private static function buildSlugUri($class, $params)
    {
        unset($params['uri']);

        $slug_uri = \URLify::filter($class);
        foreach ($params as $key => $value) {
            $slug_uri .= '/' . \URLify::filter($value);
        }
        return $slug_uri;
    }

    /**
     * @param $requestedUrl
     * @return string
     */
    public static function resolveSlugUri($requestedUrl)
    {
        $requestedUrl = substr(parse_url($requestedUrl, PHP_URL_PATH), 1);
        if ($res = $GLOBALS['DB']->statement('SELECT org_uri FROM cache_uri WHERE slug_uri=? LIMIT 1', [$requestedUrl])) {
            $requestedUrl = $res[0]['org_uri'];
            RequestHandler::loadGetParams($requestedUrl);
        }
        return $requestedUrl;
    }
}