<?php

namespace App\TwStats\Controller;

use App\TwStats\Models\GameServer;
use App\TwStats\Models\Server;


/**
 * Class NetworkController
 * @package App\TwStats\Controller
 */
class NetworkController
{
    /** @var int www.teeworlds.com has no AAAA domain record and doesn't support IPv6 only requests */
    const PROTOCOL_FAMILY = AF_INET;

    /** @var int connection timeout in ms */
    const CONNECTION_TIMEOUT = 1000;

    /** @var int sleep duration between checking if we received a packet in ms */
    const CONNECTION_SLEEP_DURATION = 50;

    const PACKETS = [
        'SERVERBROWSE_GETCOUNT' => "\xff\xff\xff\xffcou2",
        'SERVERBROWSE_COUNT' => "\xff\xff\xff\xffsiz2",
        'SERVERBROWSE_GETLIST' => "\xff\xff\xff\xffreq2",
        'SERVERBROWSE_LIST' => "\xff\xff\xff\xfflis2",
        'SERVERBROWSE_GETINFO_64_LEGACY' => "\xff\xff\xff\xfffstd",
        'SERVERBROWSE_INFO_64_LEGACY' => "\xff\xff\xff\xffdtsf",
        'SERVERBROWSE_GETINFO' => "\xff\xff\xff\xffgie3",
        'SERVERBROWSE_INFO' => "\xff\xff\xff\xffinf3",
        'SERVERBROWSE_INFO_EXTENDED' => "\xff\xff\xff\xffiext",
        'SERVERBROWSE_INFO_EXTENDED_MORE' => "\xff\xff\xff\xffiex+",
    ];

    /**
     * generate or reuse a request token
     * and send the passed data with the request token to the passed socket
     *
     * @param $sock
     * @param $data
     * @param $server
     * @return bool
     */
    public static function send_packet($sock, $data, Server &$server)
    {
        // generate 2 random bytes as server request token
        if (!$server->getAttribute('_request_token')) {
            $server->setAttribute('_request_token', random_bytes(2));
        }

        $packet = sprintf("xe%s\0\0%s", $server->getAttribute('_request_token'), $data);

        // add additional token for game server requests
        if ($server instanceof GameServer) {
            if (!$server->getAttribute('_token')) {
                $server->setAttribute('_token', random_bytes(1));
            }
            $packet .= $server->getAttribute('_token');
        }

        try {
            socket_sendto($sock, $packet, strlen($packet), 0, $server->getAttribute('ip'), $server->getAttribute('port'));
            return True;
        } /** @noinspection PhpRedundantCatchClauseInspection */
        catch (\ErrorException $err) {
            // server not reachable or we got blocked
            return False;
        }
    }

    /**
     * check if we received a packet
     * if yes check for the servers with the ip and port
     * and pass the server together with the data to the processing function given as a callback
     *
     * @param $sock
     * @param Server[] $servers
     * @param callable $processingCallback
     * @return bool
     */
    public static function receive_packet($sock, array &$servers, callable $processingCallback)
    {
        try {
            if (!socket_recvfrom($sock, $data, 2048, 0, $ip, $port)) {
                return false;
            }
        } catch (\Exception $err) {
            return false;
        }

        foreach ($servers as &$server) {
            if ($server->getAttribute('ip') == $ip && $server->getAttribute('port') == $port) {
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