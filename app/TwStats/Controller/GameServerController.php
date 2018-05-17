<?php

namespace App\TwStats\Controller;

use App\TwStats\Models\GameServer;

/**
 * Class GameServerController
 * @package App\TwStats\Controller
 */
class GameServerController
{

    const SERVER_CHUNK_SIZE = 50;

    const SERVER_CHUNK_SLEEP_MS = 1000;

    /**
     * ToDo: request the information in chunks instead of running ~900 connections parallel with one socket
     *
     * @param GameServer[] $servers
     */
    public static function fillServerInfo(array &$servers)
    {
        // create an udp protocol socket
        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        // set the socket to non blocking to allow parallel requests
        socket_set_nonblock($sock);


        /** @var GameServer $server */
        foreach ($servers as &$server) {
            NetworkController::send_packet($sock, NetworkController::PACKETS['SERVERBROWSE_GETINFO'], $server);
            NetworkController::send_packet($sock, NetworkController::PACKETS['SERVERBROWSE_GETINFO_64_LEGACY'], $server);
        }

        $durationWithoutResponse = NetworkController::CONNECTION_SLEEP_DURATION;
        usleep(NetworkController::CONNECTION_SLEEP_DURATION * 1000);

        while (true) {
            if (!NetworkController::receive_packet($sock, $servers, array(self::class, "processPacket"))) {
                if ($durationWithoutResponse > NetworkController::CONNECTION_TIMEOUT) {
                    // we didn't receive any packets in time and cancel the connection here
                    break;
                } else {
                    // increase the measured duration without a response and sleep for the set duration
                    $durationWithoutResponse += NetworkController::CONNECTION_SLEEP_DURATION;
                    usleep(NetworkController::CONNECTION_SLEEP_DURATION * 1000);
                }
            } else {
                // if we got a response reset the duration in case we receive multiple packets
                $durationWithoutResponse = 0;
            }
        }
    }

    /**
     * process packet for
     *
     * SERVERBROWSE_INFO
     * SERVERBROWSE_INFO_64_LEGACY
     * SERVERBROWSE_INFO_EXTENDED
     * SERVERBROWSE_INFO_EXTENDED_MORE
     *
     * packets
     *
     * @param $data
     * @param GameServer $server
     */
    public static function processPacket($data, GameServer &$server)
    {
        $server->setAttribute('response', true);

        // validate the server token
        $slots = explode("\x00", substr($data, 14, strlen($data) - 15));
        $token = intval($slots[0]);
        if (($token & 0xff) !== ord($server->getAttribute('_token'))) {
            // server token validation failed
            return;
        }

        switch (substr($data, 6, 8)) {
            case NetworkController::PACKETS['SERVERBROWSE_INFO']:
                // vanilla
                break;
            case NetworkController::PACKETS['SERVERBROWSE_INFO_64_LEGACY']:
                // 64 legacy version
                break;
            case NetworkController::PACKETS['SERVERBROWSE_INFO_EXTENDED']:
                if (($slots[0] & 0xffff00) >> 8 !== ((ord($server->getAttribute('_request_token')[0]) << 8) + ord($server->getAttribute('_request_token')[1]))) {
                    // additional server token validation failed
                    echo "request token validation failed";
                    return;
                }
                // extended response (contains current map size & crc)
                break;
            case NetworkController::PACKETS['SERVERBROWSE_INFO_EXTENDED_MORE']:
                // extended response even more
                break;
        }
    }
}