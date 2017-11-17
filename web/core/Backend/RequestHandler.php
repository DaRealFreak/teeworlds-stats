<?php

namespace TwStats\Core\Backend;

use TwStats\Core\Utility\GeneralUtility;
use TwStats\Core\General\SingletonInterface;

class RequestHandler implements SingletonInterface
{
    /**
     * build the called url
     *
     * @return string
     */
    public static function getUrl()
    {
        return self::getFQDN() . $_SERVER['REQUEST_URI'];
    }

    /**
     * get the fully qualified domain name
     *
     * @return string
     */
    public static function getFQDN() {
        $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'] ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : $_SERVER['REQUEST_SCHEME'];
        return "$scheme://$_SERVER[HTTP_HOST]";
    }

    /**
     * retrieve the path of the requested file in the twstats directory
     * if nothing is passed or the file doesn't exist return the path to the main file
     *
     * @return mixed
     */
    public static function getRequestedPath()
    {
        $requestedPath = substr(parse_url(self::getUrl(), PHP_URL_PATH), 1);
        if ($requestedPath) {
            $requestedPath = GeneralUtility::joinPaths(TwStats_path, $requestedPath);
            if (!@is_file($requestedPath) || !@is_file($requestedPath)) {
                $requestedPath = TwStats_main_file;
            }
        } else {
            return TwStats_main_file;
        }
        return $requestedPath;
    }

    /**
     * return the namespace of the corresponding class to the requested file
     * as per the definitions in the \TwStats\Core\Backend\SystemEnvironmentBuilder
     *
     * @return string
     */
    public static function getRequestedClass()
    {
        $requestedPath = self::getRequestedPath();
        $parsedPath = pathinfo($requestedPath);
        return TwStats_Ext_namespace . ucfirst($parsedPath['filename']);
    }

    /**
     * check if the requested argument is in either $_POST, $_GET or $_SESSION
     *
     * @param $var
     * @return bool
     */
    public static function hasArgument($var) {
        if (empty($var)) {
            return False;
        }
        if (isset($_POST[$var]) || isset($_GET[$var]) || isset($_SESSION[$var])) {
            return True;
        } else {
            return False;
        }
    }

    /**
     * get the requested argument from $_SESSION, $_POST or $_GET
     *
     * @param $var
     * @return null|string
     */
    public static function getArgument($var) {
        if (empty($var)) {
            return '';
        }
        if (isset($_SESSION[$var])) {
            $value = $_SESSION[$var];
        } elseif (isset($_GET[$var])) {
            $value = $_GET[$var];
        } elseif (isset($_POST[$var])) {
            $value = $_POST[$var];
        } else {
            $value = null;
        }
        // This is there for backwards-compatibility, in order to avoid NULL
        if (isset($value) && !is_array($value)) {
            $value = (string)$value;
        }
        return $value;
    }

    /**
     * retrieve the passed arguments
     *
     * @return mixed
     */
    public static function getArguments()
    {
        $value = array_merge($_GET, $_POST);
        if (isset($_SESSION)) {
            $value = array_merge($value, $_SESSION);
        }
        return $value;
    }
}