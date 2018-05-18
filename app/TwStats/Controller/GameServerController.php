<?php

namespace App\TwStats\Controller;

use App\TwStats\Models\GameServer;
use App\TwStats\Models\Player;

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


        $i = 0;
        /** @var GameServer $server */
        foreach ($servers as &$server) {
            NetworkController::send_packet($sock, NetworkController::PACKETS['SERVERBROWSE_GETINFO'], $server);
            NetworkController::send_packet($sock, NetworkController::PACKETS['SERVERBROWSE_GETINFO_64_LEGACY'], $server);

            if (++$i % self::SERVER_CHUNK_SIZE === 0 || $i >= count($servers)) {
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
        $token = intval(array_shift($slots));
        if (($token & 0xff) !== ord($server->getAttribute('_token'))) {
            $server->setAttribute('response', false);
            // server token validation failed
            return;
        }

        switch (substr($data, 6, 8)) {
            case NetworkController::PACKETS['SERVERBROWSE_INFO']:
                // vanilla
                $server->setAttribute('server_type', 'vanilla');

                $server->setAttribute('version', strval(array_shift($slots)));
                $server->setAttribute('name', strval(array_shift($slots)));
                $server->setAttribute('map', strval(array_shift($slots)));

                $server->setAttribute('gametype', strval(array_shift($slots)));
                $server->setAttribute('flags', intval(array_shift($slots)));
                $server->setAttribute('numplayers', intval(array_shift($slots)));
                $server->setAttribute('maxplayers', intval(array_shift($slots)));
                $server->setAttribute('numclients', intval(array_shift($slots)));
                $server->setAttribute('maxclients', intval(array_shift($slots)));

                $players = $server->getAttribute('players');
                while (count($slots)) {
                    /** @var Player $player */
                    $player = Player::make([
                        'name' => strval(array_shift($slots)),
                        'clan' => strval(array_shift($slots)),
                        'country' => intval(array_shift($slots)),
                        'score' => intval(array_shift($slots)),
                        'ingame' => intval(array_shift($slots)),
                    ]);
                    if (!$server->doesPlayerAlreadyExist($player)) {
                        $players[] = $player;
                    }
                }
                $server->setAttribute('players', $players);
                break;
            case NetworkController::PACKETS['SERVERBROWSE_INFO_64_LEGACY']:
                // 64 legacy version
                if ($server->getAttribute('server_type') != '64_legacy') {
                    $server->setAttribute('server_type', '64_legacy');
                    $server->setAttribute('players', []);
                }

                $server->setAttribute('version', strval(array_shift($slots)));
                $server->setAttribute('name', strval(array_shift($slots)));
                $server->setAttribute('map', strval(array_shift($slots)));

                $server->setAttribute('gametype', strval(array_shift($slots)));
                $server->setAttribute('flags', intval(array_shift($slots)));
                $server->setAttribute('numplayers', intval(array_shift($slots)));
                $server->setAttribute('maxplayers', intval(array_shift($slots)));
                $server->setAttribute('numclients', intval(array_shift($slots)));
                $server->setAttribute('maxclients', intval(array_shift($slots)));

                // i have no bloody idea what this packet is for
                if ($slots[0] === '') {
                    array_shift($slots);
                }

                $players = $server->getAttribute('players');
                while (count($slots)) {
                    /** @var Player $player */
                    $player = Player::make([
                        'name' => strval(array_shift($slots)),
                        'clan' => strval(array_shift($slots)),
                        'country' => intval(array_shift($slots)),
                        'score' => intval(array_shift($slots)),
                        'ingame' => intval(array_shift($slots)),
                    ]);
                    if (!$server->doesPlayerAlreadyExist($player)) {
                        $players[] = $player;
                    }
                }
                $server->setAttribute('players', $players);
                break;
            case NetworkController::PACKETS['SERVERBROWSE_INFO_EXTENDED']:
                // extended response (contains current map size & crc)
                if ($server->getAttribute('server_type') != 'ext') {
                    $server->setAttribute('server_type', 'ext');
                    $server->setAttribute('players', []);
                }

                if (($token & 0xffff00) >> 8 !== ((ord($server->getAttribute('_request_token')[0]) << 8) + ord($server->getAttribute('_request_token')[1]))) {
                    // additional server token validation failed
                    $server->setAttribute('response', false);
                    return;
                }

                $server->setAttribute('version', strval(array_shift($slots)));
                $server->setAttribute('name', strval(array_shift($slots)));
                $server->setAttribute('map', strval(array_shift($slots)));

                $server->setAttribute('mapcrc', intval(array_shift($slots)));
                $server->setAttribute('mapsize', intval(array_shift($slots)));

                $server->setAttribute('gametype', strval(array_shift($slots)));
                $server->setAttribute('flags', intval(array_shift($slots)));
                $server->setAttribute('numplayers', intval(array_shift($slots)));
                $server->setAttribute('maxplayers', intval(array_shift($slots)));
                $server->setAttribute('numclients', intval(array_shift($slots)));
                $server->setAttribute('maxclients', intval(array_shift($slots)));

                $players = $server->getAttribute('players');
                while (count($slots)) {
                    /** @var Player $player */
                    $player = Player::make([
                        'name' => strval(array_shift($slots)),
                        'clan' => strval(array_shift($slots)),
                        'country' => intval(array_shift($slots)),
                        'score' => intval(array_shift($slots)),
                        'ingame' => intval(array_shift($slots)),
                    ]);
                    // no idea what this is for, in every test case it was 0 but we gotta shift it too
                    array_shift($slots);

                    if (!$server->doesPlayerAlreadyExist($player)) {
                        $players[] = $player;
                    }
                }
                $server->setAttribute('players', $players);
                break;
            case
            NetworkController::PACKETS['SERVERBROWSE_INFO_EXTENDED_MORE']:
                // extended response even more
                $server->setAttribute('server_type', 'ext+');
                break;
        }
    }
}