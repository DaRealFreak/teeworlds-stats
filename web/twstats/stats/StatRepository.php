<?php

namespace TwStats\Ext\Stats;


use TwStats\Core\Backend\AbstractRepository;
use TwStats\Core\Utility\PrettyUrl;

class StatRepository extends AbstractRepository
{
    /**
     * @param $tsc
     * @param $name
     * @param $stat
     * @param int $nbin
     * @return array
     */
    public function gethisto($tsc, $name, $stat, $nbin = 8)
    {
        $req = $this->databaseConnection->$this->databaseConnection->sqlQuery("SELECT count AS data, stat AS label FROM data
							WHERE tcsName=? AND tcsType=?
								AND statType = ? ORDER BY data DESC",
            array($name, $tsc, $stat));
        $rows = [];

        while ($row = $this->databaseConnection->$this->databaseConnection->sqlFetch($req)) {
            $row['data'] = (int)$row['data'];
            $rows[] = $row;
        }

        $c = count($rows);
        $other = 0;
        if (count($rows) >= $nbin) {
            for ($i = ($nbin - 1); $i < $c; $i++) {
                $other += $rows[$i]['data'];
                unset($rows[$i]);
            }
            $rows[$nbin - 1] = array("label" => "other", "data" => $other);
        }

        return $rows;
    }

    /**
     * @param $stat
     * @param int $nbin
     * @return array
     */
    public function getglobalhisto($stat, $nbin = 8)
    {
        $req = $this->databaseConnection->sqlQuery("SELECT count AS data, stat AS label FROM general
							WHERE statType = ? ORDER BY data DESC", array($stat));
        $rows = [];

        while ($row = $this->databaseConnection->sqlFetch($req)) {
            $row['data'] = (int)$row['data'];
            $rows[] = $row;
        }

        $c = count($rows);
        $other = 0;
        if (count($rows) >= $nbin) {
            for ($i = ($nbin - 1); $i < $c; $i++) {
                $other += $rows[$i]['data'];
                unset($rows[$i]);
            }
            $rows[$nbin - 1] = array("label" => "other", "data" => $other);
        }

        return $rows;
    }

    /**
     * @param $clan
     * @return array
     */
    public function getClanPlayers($clan)
    {
        $res = [];
        $req = $this->databaseConnection->sqlQuery("SELECT tee, lastseen,
						lastseen > NOW( ) - INTERVAL 5 MINUTE AS online
					FROM tees WHERE clan=?
					ORDER BY online DESC, lastseen DESC LIMIT 30", array($clan));

        while ($row = $this->databaseConnection->sqlFetch($req)) {
            $res[] = array('name' => $row['tee'],
                'lastseen' => $row['lastseen'],
                'online' => $row['online']);
        }

        return $res;
    }

    /**
     * @param $server
     * @return array
     */
    public function getServerPlayers($server)
    {
        $res = [];

        $req = $this->databaseConnection->sqlQuery("SELECT tee, lastseen,
						lastseen > NOW( ) - INTERVAL 5 MINUTE AS online
					FROM tees
					WHERE server=? AND lastseen > NOW( ) - INTERVAL 5 MINUTE
					ORDER BY online DESC, lastseen DESC", array($server));

        while ($row = $this->databaseConnection->sqlFetch($req)) {
            $res[] = array('name' => $row['tee'],
                'lastseen' => $row['lastseen'],
                'online' => $row['online']);
        }

        return $res;
    }

    /**
     * @param $tcs
     * @param $tcsName
     * @return array
     */
    public function gethours($tcs, $tcsName)
    {
        $req = $this->databaseConnection->sqlQuery("SELECT count AS `Lignes`, stat AS heure FROM data
						WHERE tcsType = ? AND tcsName = ? AND statType = 'hour'
							ORDER BY stat", array($tcs, $tcsName));
        $sum = 0;
        $res = [];
        $res2 = [];
        while ($data = $this->databaseConnection->sqlFetch($req)) {
            $res[(int)$data["heure"]] = (int)$data["Lignes"];
            $sum += (int)$data["Lignes"];
        }

        for ($i = 0; $i < 24; $i++) {
            $res2[] = array($i, isset($res[$i]) ? round($res[$i] * 100 / $sum) : 0);
        }

        return $res2;
    }

    /**
     * @param $tcs
     * @param $tcsName
     * @return array
     */
    public function getdays($tcs, $tcsName)
    {
        $req = $this->databaseConnection->sqlQuery("SELECT count AS `Lignes`, stat AS day FROM data
						WHERE tcsType = ? AND tcsName = ? AND statType = 'day'
							ORDER BY stat", array($tcs, $tcsName));
        $sum = 0;
        $res = [];
        $res2 = [];
        while ($data = $this->databaseConnection->sqlFetch($req)) {
            $res[$data["day"]] = (int)$data["Lignes"];
            $sum += (int)$data["Lignes"];
        }

        $tr = array('Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 7);
        $tmp = [];
        foreach ($tr as $day => $num) {
            $tmp[$num] = empty($res[$day]) ? 0 : $res[$day];
        }

        foreach ($tmp as $num => $c) {
            $res2[] = array($num, round($c * 100 / $sum));
        }

        return $res2;
    }

    /**
     * @param $str
     * @return bool|mixed|string
     */
    public function autolink($str)
    {
        $str = ' ' . $str;
        $str = preg_replace(
            '`([^"=\'>])(www\.[^\s<]+[^\s<\.)])`i',
            '$1<a href="http://$2">$2</a>',
            $str
        );
        $str = substr($str, 1);

        return $str;
    }

    /**
     * @param $field
     * @param $value
     * @return bool
     */
    public function cexists($field, $value)
    {
        global $db;

        $req = $db->prepare("SELECT * FROM data WHERE tcsName = ? AND tcsType = ? LIMIT 1 ");
        $req->execute(array($value, $field));

        return $req->fetch(\PDO::FETCH_ASSOC) != false;
    }

    /**
     * @param $field
     * @param $value
     * @return array
     */
    public function similarvalues($field, $value)
    {
        global $db;

        $req = $db->prepare("SELECT DISTINCT tcsName AS label FROM data
							WHERE tcsName LIKE ? AND tcsType=? LIMIT 10 ");
        $req->execute(array("%$value%", $field));

        $res = [];

        while ($data = $req->fetch(\PDO::FETCH_ASSOC)) {
            $res[] = $data["label"];
        }

        return $res;
    }

    /**
     * @param $tcs
     * @param $name
     * @return array
     */
    public function getSimilarData($tcs, $name)
    {
        $req = $this->databaseConnection->sqlQuery("SELECT DISTINCT $tcs FROM tees WHERE tee LIKE ? LIMIT 10", array("%$name%"));
        $res = [];
        while ($data = $this->databaseConnection->sqlFetch($req)) {
            $res[PrettyUrl::buildPrettyUri("$tcs", array('n' => $data[$tcs]))] = $data[$tcs];
        }
        return $res;
    }

    /**
     * @param $name
     * @return bool|mixed
     */
    public function getPlayer($name)
    {
        global $db;

        $req = $db->prepare("SELECT * FROM tees WHERE tee=?");
        $req->execute(array($name));
        if ($data = $req->fetch(\PDO::FETCH_ASSOC)) {
            return $data;
        }
        return false;
    }

    /**
     * @param $tee
     * @return bool
     */
    public function getTeeName($tee)
    {
        $req = $this->databaseConnection->sqlQuery("SELECT tee FROM tees WHERE tee=? LIMIT 1", array($tee));
        $tmp = $this->databaseConnection->sqlFetch($req);
        return $tmp ? $tmp['tee'] : false;
    }

    /**
     * @param $clan
     * @return bool
     */
    public function getClanName($clan)
    {
        $req = $this->databaseConnection->sqlQuery("SELECT clan FROM tees WHERE clan=? LIMIT 1", array($clan));
        $tmp = $this->databaseConnection->sqlFetch($req);
        return $tmp ? $tmp['clan'] : false;
    }

    /**
     * @param $server
     * @return bool
     */
    public function getServerName($server)
    {
        $req = $this->databaseConnection->sqlQuery("SELECT server FROM tees WHERE server=? LIMIT 1", array($server));
        $tmp = $this->databaseConnection->sqlFetch($req);
        return $tmp ? $tmp['server'] : false;
    }

    /**
     * @param $nb
     * @return string
     */
    public function niceDigits($nb)
    {
        return strrev(implode(" ", str_split(strrev($nb), 3)));
    }

    /**
     * @return array
     */
    public function generalCounts()
    {
        $req = $this->databaseConnection->sqlQuery("SELECT count(*) AS ncountries FROM general WHERE statType='country'");
        $res = $this->databaseConnection->sqlFetch($req);

        $req = $this->databaseConnection->sqlQuery("SELECT count(*) AS nmods FROM general WHERE statType='mod'");
        $res += $this->databaseConnection->sqlFetch($req);

        $req = $this->databaseConnection->sqlQuery("SELECT count(*) AS nnames FROM tees");
        $res += $this->databaseConnection->sqlFetch($req);

        $req = $this->databaseConnection->sqlQuery("SELECT count(*) AS nservers FROM servers");
        $res += $this->databaseConnection->sqlFetch($req);

        $req = $this->databaseConnection->sqlQuery("SELECT count(*) AS nonline FROM tees WHERE lastseen > now() - INTERVAL 6 MINUTE");
        $res += $this->databaseConnection->sqlFetch($req);

        return array_map("niceDigits", $res);
    }
}