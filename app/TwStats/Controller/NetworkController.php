<?php

namespace App\TwStats\Controller;


/**
 * Class NetworkController
 * @package App\TwStats\Controller
 */
class NetworkController
{
    /** @var int connection timeout in ms */
    const CONNECTION_TIMEOUT = 1000;

    /** @var int sleep duration between checking if we received a packet in ms */
    const CONNECTION_SLEEP_DURATION = 50;

    const PACKETS = [
        'SERVERBROWSE_GETCOUNT' => "\xff\xff\xff\xffcou2",
        'SERVERBROWSE_COUNT' => "\xff\xff\xff\xffsiz2",
        'SERVERBROWSE_GETLIST' => "\xff\xff\xff\xffreq2",
        'SERVERBROWSE_LIST' => "\xff\xff\xff\xfflis2"
    ];

    /**
     * generate or reuse a request token
     * and send the passed data with the request token to the passed socket
     *
     * @param $sock
     * @param $data
     * @param $server
     */
    public static function send_packet($sock, $data, &$server)
    {
        // generate 2 random bytes as server request token
        if (!isset($server['_request_token'])) {
            $server['_request_token'] = random_bytes(2);
        }

        $packet = sprintf("xe%s\0\0%s", $server['_request_token'], $data);

        socket_sendto($sock, $packet, strlen($packet), 0, $server['hostname'], $server['port']);
    }

    /**
     * check if we received a packet
     * if yes check for the servers with the ip and port
     * and pass the server together with the data to the processing function given as a callback
     *
     * @param $sock
     * @param $servers
     * @param callable $processingCallback
     * @return bool
     */
    public static function receive_packet($sock, &$servers, callable $processingCallback)
    {
        if (!socket_recvfrom($sock, $data, 2048, 0, $ip, $port)) {
            return false;
        }

        foreach ($servers as &$server) {
            if ($server['ip'] == $ip && $server['port'] == $port) {
                $processingCallback($data, $server);
            }
        }
        return true;
    }

    /**
     * change binary data to a pretty formatted hex string
     * example: 11010011 11010011 will get returned as \xD3\xD3
     *
     * @param $str
     * @return string
     */
    public static function hexEntities($str)
    {
        $prettyString = bin2hex($str);
        $prettyString = chunk_split($prettyString, 2, "\\x");
        $prettyString = "\\x" . substr($prettyString, 0, -2);
        return $prettyString;
    }

}