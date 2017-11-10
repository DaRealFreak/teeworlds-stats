<?php

namespace TwStats\Core\Backend;


use TwStats\Core\Utility\GeneralUtility;

class RequestHandler
{
    /**
     * build the called url
     *
     * @return string
     */
    public static function getUrl() {
        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'] ? $_SERVER['HTTP_X_FORWARDED_PROTO']: $_SERVER['REQUEST_SCHEME'];
        return "$scheme://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    }

    /**
     * retrieve the requested path
     * if nothing is passed return empty string to execute the main action
     *
     * @return mixed
     */
    public static function getRequestedPath() {
        $requestedPath = substr(parse_url(self::getUrl(), PHP_URL_PATH), 1);
        if ($requestedPath) {
            return GeneralUtility::joinPaths(TwStats_path, $requestedPath);
        } else {
            return $requestedPath;
        }
    }

    /**
     * retrieve the passed arguments
     *
     * @return mixed
     */
    public static function getArguments() {
        return parse_url(self::getUrl(), PHP_URL_QUERY);
    }
}