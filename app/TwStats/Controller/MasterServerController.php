<?php

namespace App\TwStats\Controller;

/**
 * Class MasterServerController
 * @package App\TwStats\Controller
 */
class MasterServerController
{

    /** @var array teeworlds master servers */
    protected static $masterservers = [
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

    /**
     * check the master servers for the game server count
     * and retrieve the server list
     *
     * @return array
     */
    public static function getServers()
    {
        // create an udp protocol socket
        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        // set the socket to non blocking to allow parallel requests
        socket_set_nonblock($sock);

        foreach (self::$masterservers as &$server) {
            // if we are using DNS entries resolve the ip to handle the response properly
            if (!isset($server['ip'])) {
                $server['ip'] = gethostbyname($server['hostname']);
            }

            $server['response'] = false;

            NetworkController::send_packet($sock, NetworkController::PACKETS['SERVERBROWSE_GETCOUNT'], $server);
            NetworkController::send_packet($sock, NetworkController::PACKETS['SERVERBROWSE_GETLIST'], $server);
        }

        $durationWithoutResponse = NetworkController::CONNECTION_SLEEP_DURATION;
        usleep(NetworkController::CONNECTION_SLEEP_DURATION * 1000);

        while (true) {
            if (!NetworkController::receive_packet($sock, self::$masterservers, array(self::class, "processPacket"))) {
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

        return self::$masterservers;
    }

    /**
     * process packet for SERVERBROWSE_COUNT & SERVERBROWSE_LIST packets
     *
     * @param $data
     * @param $server
     */
    public static function processPacket($data, &$server)
    {
        $server['response'] = true;

        switch (substr($data, 6, 8)) {

            // we are receiving the information how many game servers we get from this master server
            case NetworkController::PACKETS['SERVERBROWSE_COUNT']:
                $server['num_servers'] = ((ord($data[14]) << 8) | ord($data[15]));
                break;

            // we are receiving the information of the game servers
            case NetworkController::PACKETS['SERVERBROWSE_LIST']:
                for ($i = 14; ($i + 18) <= strlen($data); $i += 18) {

                    /* switch between IPv4 and IPv6 */
                    if (substr($data, $i, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
                        $ip = inet_ntop(substr($data, $i + 12, 4)); // IPv4
                    } else {
                        $ip = "[" . inet_ntop(substr($data, $i, 16)) . "]"; // IPv6
                    }

                    $port = hexdec(bin2hex(substr($data, $i + 16, 2)));
                    $server['servers'][] = ['ip' => $ip, 'port' => $port];
                }
                break;

            // unknown packet
            default:
                break;
        }
    }
}