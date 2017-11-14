<?php

namespace TwStats\Core\Backend;

use TwStats\Core\Utility\SingletonInterface;

class SessionHandler implements SingletonInterface
{
    /**
     * returns all keys set in $_SESSION
     *
     * @return mixed
     */
    public static function getArguments()
    {
        return array_keys($_SESSION);
    }

    /**
     * check if passed argument is set in $_SESSION
     *
     * @param $argument
     * @return bool
     */
    public static function hasArgument($argument)
    {
        return isset($_SESSION) && isset($_SESSION[$argument]);
    }

    /**
     * check if passed argument is set in $_SESSION
     * and return if set, else return false
     *
     * @param $argument
     * @return mixed|bool
     */
    public static function getArgument($argument)
    {
        if (self::hasArgument($argument)) {
            return $_SESSION[$argument];
        }
        return false;
    }

    /**
     * check if passed argument is set in $_SESSION
     * and unset it if set
     *
     * @param $argument
     */
    public static function removeArgument($argument)
    {
        if (self::hasArgument($argument)) {
            unset($_SESSION[$argument]);
        }
    }
}