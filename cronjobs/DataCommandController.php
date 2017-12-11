<?php

namespace TwStats\Cron;


use TwStats\Core\Console\AbstractController;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Ext\TwRequest\TwRequest;

class DataCommandController extends AbstractController
{
    /**
     * gather data from the collected servers
     */
    public function gatherData()
    {
        $timeA = time();
        ini_set("max_execution_time", 10 * 60);

        $curdate = date('Y-m-d H:i:s');

        /** @var TwRequest $twdata */
        $twdata = GeneralUtility::makeInstance(TwRequest::class);

        //$twdata->loadServersFromMasterservers();

        $req = $this->databaseConnection->sqlQuery("SELECT `address`, `port` FROM servers");
        $servers = array();

        while ($data = $req->fetch(\PDO::FETCH_ASSOC)) {
            $servers[] = array(0 => $data['address'], 1 => $data['port'], 2 => 6);
        }

        $twdata->addServers($servers);
        $twdata->loadServerInfo();

        $data = $twdata->getServers();

        $timeB = time();
        $dT = $timeB - $timeA;

        echo "Gathering all servers in $dT s\n";

        $timeB = time();

        $general = array();
        foreach ($data as $serverInfo) {
            if (isset($serverInfo["players"])) {
                // many servers currently increased the slot capacity to 64
                // since the original client has it set on 16 they display the current
                // slot count in the server name like xx [2/64]
                // this is undesired in the statistics, so we split it off here
                $re = '/(.*) \[\d+\/\d+\]/';
                preg_match_all($re, $serverInfo['name'], $matches, PREG_SET_ORDER, 0);
                if (isset($matches[0][1])) {
                    $serverInfo['name'] = $matches[0][1];
                }

                foreach ($serverInfo['players'] as $player) {
                    if ($player["name"] === '(connecting)') {
                        continue;
                    }

                    $tees['tee'] = $player["name"];
                    $tees['server'] = $serverInfo["name"];
                    $tees['clan'] = $player["clan"];
                    $tees['firstseen'] = $curdate;
                    $tees['lastseen'] = $curdate;

                    $this->insertOrUpdatePlayer($tees);

                    $tee = $player["name"];
                    $clan = $player["clan"];
                    $server = $serverInfo["name"];

                    $stat['mod'] = $serverInfo["gametype"];
                    $stat['map'] = $serverInfo["map"];
                    $stat['country'] = $twdata->getCountryName($player["country"]);
                    $stat['hour'] = date('H');
                    $stat['day'] = date('D');

                    if (!in_array(strtolower($tee), array('(connecting)', 'nameless tee'))) {
                        foreach (array('mod', 'map', 'hour', 'day') as $st) {
                            $this->insertOrUpdatePlayerData(array($tee, 'tee', $stat[$st], $st));
                        }
                    }

                    if (!empty($clan)) {
                        foreach (array('mod', 'map', 'hour', 'day', 'country') as $st) {
                            $this->insertOrUpdatePlayerData(array($clan, 'clan', $stat[$st], $st));
                        }
                    }

                    foreach (array('map', 'hour', 'day', 'country') as $st) {
                        $this->insertOrUpdatePlayerData(array($server, 'server', $stat[$st], $st));
                    }

                    if (!isset($general['mod'][$stat['mod']])) {
                        $general['mod'][$stat['mod']] = 1;
                    } else {
                        $general['mod'][$stat['mod']] += 1;
                    }

                    if (!isset($general['country'][$stat['country']])) {
                        $general['country'][$stat['country']] = 1;
                    } else {
                        $general['country'][$stat['country']] += 1;
                    }
                }
            }
        }

        foreach ($general['mod'] as $mod => $count) {
            $this->insertOrUpdateGeneralData($mod, 'mod', $count);
        }
        foreach ($general['country'] as $mod => $count) {
            $this->insertOrUpdateGeneralData($mod, 'country', $count);
        }

        $timeC = time();
        $dT = $timeC - $timeB;

        echo "Parsing all playerdata in $dT s\n";
        // ToDo: why was this commented out?
        // $this->cacheData($data);
    }

    /**
     * insert or update a player.
     * if the player already exists update the last seen date
     *
     * @param array $player
     */
    private function insertOrUpdatePlayer($player)
    {
        if ($existingTee = $this->databaseConnection->statement(
            'SELECT uid FROM tees WHERE tee=? AND clan=?',
            [$player['tee'], $player['clan']]
        )) {
            $req = $this->databaseConnection->sqlPrepare('UPDATE tees SET server=?, lastseen=? WHERE  uid=?;');
            $req->execute([$player['server'], $player['lastseen'], $existingTee[0]['uid']]);
        } else {
            $req = $this->databaseConnection->sqlPrepare("INSERT INTO tees (tee,server,clan,firstseen,lastseen) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE server=?, clan=?, lastseen=?");
            $req->execute(array($player['tee'], $player['server'], $player['clan'], $player['firstseen'], $player['lastseen'], $player['server'], $player['clan'], $player['lastseen']));
        }

    }

    /**
     * insert or update the different stats of the players like map, stat etc
     * if entry already exists increase count by 1
     *
     * @param array $values
     */
    private function insertOrUpdatePlayerData($values = [])
    {
        if ($existingEntry = $this->databaseConnection->statement(
            'SELECT `uid`, `count` FROM `data` WHERE `tcsName`=? AND `tcsType`=? AND `stat`=? AND `statType`=?', $values)
        ) {
            $req = $this->databaseConnection->sqlPrepare("
              UPDATE `data` SET `tcsName`=?, `tcsType`=?, `stat`=?, `statType`=?, `count`=? WHERE `uid`=?;
            ");
            $req->execute(array_merge($values, [(int)$existingEntry[0]['count'] + 1, $existingEntry[0]['uid']]));
        } else {
            $req = $this->databaseConnection->sqlPrepare(
                "INSERT INTO `data` (`tcsName`, `tcsType`, `stat`, `statType`, `count`) VALUES (?, ?, ?, ?, 1)"
            );
            $req->execute($values);
        }
    }

    /**
     * keep track of the general data like countries and different mods
     *
     * @param $mod
     * @param $statType
     * @param $count
     */
    private function insertOrUpdateGeneralData($mod, $statType, $count)
    {
        // 'stat' => $mod, 'statType' => 'mod', 'count' => $count), "general", "count = count + $count");
        //
        if ($existingEntry = $this->databaseConnection->statement(
            'SELECT `uid`, `count` FROM `general` WHERE `stat`=? AND `statType`=?', [$mod, $statType])
        ) {
            $req = $this->databaseConnection->sqlPrepare("
              UPDATE `general` SET `count`=? WHERE `uid`=?;
            ");
            $req->execute([(int)$existingEntry[0]['count'] + $count, $existingEntry[0]['uid']]);
        } else {
            $req = $this->databaseConnection->sqlPrepare(
                "INSERT INTO `general` (`stat`, `statType`, `count`) VALUES (?, ?, ?)"
            );
            $req->execute([$mod, $statType, $count]);
        }
    }

    /**
     * deletes cached data from the collected servers
     */
    public function deleteData()
    {
        $curdate = date('Y-m-d H:i:s');
        $maxdate = date("Y-m-d H:i:s", strtotime($curdate) - 4 * 24 * 3600);

        $req = $this->databaseConnection->sqlPrepare("DELETE FROM chdata_clan WHERE curdate<=?");
        $req->execute(array($maxdate));
        $req = $this->databaseConnection->sqlPrepare("DELETE FROM chdata_playername WHERE curdate<=?");
        $req->execute(array($maxdate));
        $req = $this->databaseConnection->sqlPrepare("DELETE FROM chdata_servername WHERE curdate<=?");
        $req->execute(array($maxdate));

        $req = $this->databaseConnection->sqlPrepare("DELETE FROM cache_clan WHERE timestamp<=?");
        $req->execute(array(date("Y-m-d H:i:s", time() - 9 * 60)));
        $req = $this->databaseConnection->sqlPrepare("DELETE FROM cache_player WHERE timestamp<=?");
        $req->execute(array(date("Y-m-d H:i:s", time() - 9 * 60)));
        $req = $this->databaseConnection->sqlPrepare("DELETE FROM cache_server WHERE timestamp<=?");
        $req->execute(array(date("Y-m-d H:i:s", time() - 9 * 60)));
    }

    /**
     * @param array $data
     */
    public function cacheData($data)
    {
        $curdate = date('Y-m-d H:i:s');

        foreach ($data as $serverInfo) {
            if (isset($serverInfo["players"])) {
                foreach ($serverInfo['players'] as $player) {
                    $rec = array();
                    $rec["curdate"] = $curdate;
                    $rec["playername"] = $player["name"];
                    $rec["clan"] = $player["clan"];
                    $rec["country"] = TwRequest::getCountryName($player["country"]);
                    $rec["score"] = $player["score"];
                    $rec["servername"] = $serverInfo["name"];
                    $rec["map"] = $serverInfo["map"];
                    $rec["gametype"] = $serverInfo["gametype"];

                    if ($player["ingame"] && $rec["playername"] != "(connecting)") {
                        //insert_into($rec,"chdata");
                        unset($rec["score"]);
                        $this->databaseConnection->sqlInsert($rec, "chdata_clan");
                        $this->databaseConnection->sqlInsert($rec, "chdata_playername");
                        $this->databaseConnection->sqlInsert($rec, "chdata_servername");
                    }
                }
            }
        }

        //echo "Inserts dans la BDD : $dT s\n";
    }
}
