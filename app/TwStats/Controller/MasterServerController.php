<?php

namespace App\TwStats\Controller;
use App\TwStats\Models\GameServer;
use App\TwStats\Models\MasterServer;

/**
 * Class MasterServerController
 * @package App\TwStats\Controller
 */
class MasterServerController
{

    /** @var array teeworlds master servers */
    protected static $masterServers = [
        [
            "hostname" => "master1.teeworlds.com",
            "port" => 8300
        ],
        [
            "hostname" => "master2.teeworlds.com",
            "port" => 8300
        ],
        [
            "hostname" => "master3.teeworlds.com",
            "port" => 8300
        ],
        [
            "hostname" => "master4.teeworlds.com",
            "port" => 8300
        ]
    ];

    public static function getMasterServers()
    {
        foreach (self::$masterServers as $masterServer) {
            yield MasterServer::make([
                'hostname' => $masterServer['hostname'],
                'ip' => gethostbyname($masterServer['hostname']),
                'port' => $masterServer['port'],
            ]);
        }
    }

    /**
     * check the master servers for the game server count
     * and retrieve the server list
     *
     * @return MasterServer[]
     */
    public static function getServers()
    {
        // create an udp protocol socket
        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        // set the socket to non blocking to allow parallel requests
        socket_set_nonblock($sock);

        $masterServers = iterator_to_array(self::getMasterServers());

        /** @var MasterServer $server */
        foreach ($masterServers as &$server) {
            NetworkController::send_packet($sock, NetworkController::PACKETS['SERVERBROWSE_GETCOUNT'], $server);
            NetworkController::send_packet($sock, NetworkController::PACKETS['SERVERBROWSE_GETLIST'], $server);
        }

        $durationWithoutResponse = NetworkController::CONNECTION_SLEEP_DURATION;
        usleep(NetworkController::CONNECTION_SLEEP_DURATION * 1000);

        while (true) {
            if (!NetworkController::receive_packet($sock, $masterServers, array(self::class, "processPacket"))) {
                if ($durationWithoutResponse > NetworkController::CONNECTION_TIMEOUT) {
                    // we didn't receive any packets in time and cancel the connection here
                    socket_close($sock);
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

        return $masterServers;
    }

    /**
     * process packet for
     *
     * SERVERBROWSE_COUNT
     * SERVERBROWSE_LIST
     *
     * packets
     *
     * @param $data
     * @param $server
     */
    public static function processPacket($data, MasterServer &$server)
    {
        $server->setAttribute('response', true);

        // validate the server token
        $token = intval(explode("\x00", substr($data, 14, strlen($data) - 15))[0]);
        if (($token & 0xff) !== ord($server->getAttribute('_token'))) {
            // server token validation failed
            exit;
        }

        switch (substr($data, 6, 8)) {

            // we are receiving the information how many game servers we get from this master server
            case NetworkController::PACKETS['SERVERBROWSE_COUNT']:
                $server->setAttribute('num_servers', ((ord($data[14]) << 8) | ord($data[15])));
                break;

            // we are receiving the information of the game servers
            case NetworkController::PACKETS['SERVERBROWSE_LIST']:
                /** @var GameServer[] $currentGameServers */
                $currentGameServers = $server->getAttribute('servers');
                for ($i = 14; ($i + 18) <= strlen($data); $i += 18) {

                    /* switch between IPv4 and IPv6 */
                    if (substr($data, $i, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
                        $ip = inet_ntop(substr($data, $i + 12, 4)); // IPv4
                    } else {
                        $ip = "[" . inet_ntop(substr($data, $i, 16)) . "]"; // IPv6
                    }

                    $port = hexdec(bin2hex(substr($data, $i + 16, 2)));
                    $currentGameServers[] = GameServer::make([
                        'ip' => $ip,
                        'port' => $port
                    ]);
                }
                $server->setAttribute('servers', $currentGameServers);
                break;

            // unknown packet
            default:
                break;
        }
    }
}