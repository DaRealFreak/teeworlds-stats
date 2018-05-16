<?php

namespace App\TwStats\Controller;

/**
 * Class GameServerController
 * @package App\TwStats\Controller
 */
class GameServerController
{

    const SERVER_CHUNK_SIZE = 50;

    const SERVER_CHUNK_SLEEP_MS = 1000;

    /**
     *
     *
     * @param $servers
     */
    public static function fillServerInfo(&$servers) {
        $i = 0;
        foreach ($servers as &$server) {
            $server['test'] = "hello";

            // sleep after filling a chunk
            if (++$i % self::SERVER_CHUNK_SIZE == 0) {
                usleep(self::SERVER_CHUNK_SLEEP_MS * 1000);
            }
        }
    }
}