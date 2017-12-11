<?php

namespace TwStats\Core\Utility;


class StringUtility
{
    /**
     * custom strrpos function to get the offset parameter
     * to function like one
     *
     * @param $haystack
     * @param $needle
     * @param int $offset
     * @return bool|int
     */
    public static function strrpos_handmade($haystack, $needle, $offset = 0)
    {
        // if no offset is set use the original function
        if ($offset === 0) {
            return strrpos($haystack, $needle);
        }

        $length = strlen($haystack);
        $size = strlen($needle);

        if ($offset < 0) {
            $virtual_cut = $length + $offset;
            $haystack = substr($haystack, 0, $virtual_cut + $size);
            $ret = strrpos($haystack, $needle);
            return $ret > $virtual_cut ? false : $ret;
        } else {
            $haystack = substr($haystack, $offset);
            $ret = strrpos($haystack, $needle);
            return $ret === false ? $ret : $ret + $offset;
        }

    }

    /**
     * check if string starts with needle
     *
     * @param $haystack
     * @param $needle
     * @return bool
     */
    public static function startsWith($haystack, $needle)
    {
        $length = strlen($needle);
        return (substr($haystack, 0, $length) === $needle);
    }

    /**
     * check if string ends with needle
     *
     * @param $haystack
     * @param $needle
     * @return bool
     */
    public static function endsWith($haystack, $needle)
    {
        $length = strlen($needle);
        return $length === 0 || (substr($haystack, -$length) === $needle);
    }

}