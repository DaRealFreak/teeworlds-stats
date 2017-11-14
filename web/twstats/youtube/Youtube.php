<?php

namespace TwStats\Ext\Youtube;


class Youtube
{
    /**
     * @param $text
     * @return mixed
     */
    public static function integrateYoutubeVideos($text) {
        $pattern = '/http:\/\/www\.youtube\.com\/watch\?(.*?)v=([a-zA-Z0-9_\-]+)/i';
        $replace = '<iframe title="YouTube" class="youtube" type="text/html" width="560" height="315" src="http://www.youtube.com/embed/$2" frameborder="0" allowFullScreen></iframe>';
        $string = preg_replace($pattern, $replace, $text);

        return $string;
    }
}