<?php

function gethisto($tsc, $name, $stat, $nbin = 8) {
	$req = sqlquery("SELECT count as data, stat as label FROM data
							WHERE tcsName=? AND tcsType=?
								AND statType = ? ORDER BY data DESC",
									array($name, $tsc, $stat));
	$rows = array();

	while($row = sqlfetch($req)) {
		$row['data'] = (int)$row['data'];
		$rows[] = $row;
	}

	$c = count($rows);
	$other = 0;
	if(count($rows) >= $nbin) {
		for($i = ($nbin - 1) ; $i < $c ; $i++) {
			$other += $rows[$i]['data'];
			unset($rows[$i]);
		}
		$rows[$nbin-1] = array("label" => "other", "data" => $other);
	}

	return $rows;
}


function getglobalhisto($stat, $nbin = 8) {
	$req = sqlquery("SELECT count as data, stat as label FROM general
							WHERE statType = ?
								ORDER BY data DESC",
									array($stat));
	$rows = array();

	while($row = sqlfetch($req)) {
		$row['data'] = (int)$row['data'];
		$rows[] = $row;
	}

	$c = count($rows);
	$other = 0;
	if(count($rows) >= $nbin) {
		for($i = ($nbin - 1) ; $i < $c ; $i++) {
			$other += $rows[$i]['data'];
			unset($rows[$i]);
		}
		$rows[$nbin-1] = array("label" => "other", "data" => $other);
	}

	return $rows;
}

function getClanPlayers($clan) {
	$res = array();

	$req = sqlquery("SELECT tee, lastseen,
						lastseen > NOW( ) - INTERVAL 5 MINUTE as online
					FROM tees WHERE clan=?
					ORDER BY online DESC, lastseen DESC LIMIT 30", array($clan));

	while($row = sqlfetch($req)) {
		$res[] = array('name' => $row['tee'],
					   'lastseen' => $row['lastseen'],
					   'online' => $row['online']);
	}

	return $res;
}

function getServerPlayers($server) {
	$res = array();

	$req = sqlquery("SELECT tee, lastseen,
						lastseen > NOW( ) - INTERVAL 5 MINUTE as online
					FROM tees
					WHERE server=? AND lastseen > NOW( ) - INTERVAL 5 MINUTE
					ORDER BY online DESC, lastseen DESC", array($server));

	while($row = sqlfetch($req)) {
		$res[] = array('name' => $row['tee'],
					   'lastseen' => $row['lastseen'],
					   'online' => $row['online']);
	}

	return $res;
}

function gethours($tcs, $tcsName) {
	$req = sqlquery("SELECT count AS `Lignes`, stat as heure FROM data
						WHERE tcsType = ? AND tcsName = ? AND statType = 'hour'
							ORDER BY stat", array($tcs, $tcsName));
	$sum = 0;
	$res = array();
	while($data = sqlfetch($req)) {
		$res[(int)$data["heure"]] = (int)$data["Lignes"];
		$sum += (int)$data["Lignes"];
	}

	for($i=0 ; $i<24 ; $i++)
		$res2[] = array($i, isset($res[$i]) ? round($res[$i]*100/$sum) : 0);

	return $res2;
}

function getdays($tcs, $tcsName) {
	$req = sqlquery("SELECT count AS `Lignes`, stat as day FROM data
						WHERE tcsType = ? AND tcsName = ? AND statType = 'day'
							ORDER BY stat", array($tcs, $tcsName));
	$sum = 0;
	$res = array();
	while($data = sqlfetch($req)) {
		$res[$data["day"]] = (int)$data["Lignes"];
		$sum += (int)$data["Lignes"];
	}

	$tr = array('Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6,'Sun'=>7);
	foreach($tr as $day => $num)
		$tmp[$num] = empty($res[$day]) ? 0 : $res[$day];

	foreach($tmp as $num => $c)
		$res2[] = array($num, round($c*100/$sum));

	return $res2;
}


function autolink($str) {
	$str = ' ' . $str;
	$str = preg_replace(
		'`([^"=\'>])(www\.[^\s<]+[^\s<\.)])`i',
		'$1<a href="http://$2">$2</a>',
		$str
	);
	$str = substr($str, 1);

	return $str;
}


function cexists($field, $value) {
	global $db;

	$req = $db->prepare("SELECT * FROM data WHERE tcsName = ? AND tcsType = ? LIMIT 1 ");
	$req->execute(array($value,$field));

	return $req->fetch(PDO::FETCH_ASSOC) != false;
}

function similarvalues($field, $value) {
	global $db;

	$req = $db->prepare("SELECT DISTINCT tcsName as label FROM data
							WHERE tcsName LIKE ? AND tcsType=? LIMIT 10 ");
	$req->execute(array("%$value%",$field));

	$res = array();

	while($data = $req->fetch(PDO::FETCH_ASSOC))
		$res[] = $data["label"];

	return $res;
}

function getSimilarData($tcs,$name) {
	$req = sqlquery("SELECT DISTINCT $tcs FROM tees WHERE tee LIKE ? LIMIT 10", array("%$name%"));
	while($data = sqlfetch($req)) {
		$res[myurl("$tcs", array('n' => $data[$tcs]))] = $data[$tcs];
	}
	return $res;
}
function getPlayer($name) {
	global $db;

	$req = $db->prepare("SELECT * FROM tees WHERE tee=?");
	$req->execute(array($name));
	if($data = $req->fetch(PDO::FETCH_ASSOC))
		return $data;
	return false;
}

function getTeeName($tee) {
	$req = sqlquery("SELECT tee FROM tees WHERE tee=? LIMIT 1", array($tee));
	$tmp = sqlfetch($req);
	return $tmp ? $tmp['tee'] : false;
}

function getClanName($clan) {
	$req = sqlquery("SELECT clan FROM tees WHERE clan=? LIMIT 1", array($clan));
	$tmp = sqlfetch($req);
	return $tmp ? $tmp['clan'] : false;
}

function getServerName($server) {
	$req = sqlquery("SELECT server FROM tees WHERE server=? LIMIT 1", array($server));
	$tmp = sqlfetch($req);
	return $tmp ? $tmp['server'] : false;
}

function niceDigits($nb) {
	return strrev(implode(" ",str_split(strrev($nb),3)));
}
function generalCounts() {
	$req = sqlquery("SELECT count(*) as ncountries FROM general WHERE statType='country'");
	$res = sqlfetch($req);

	$req = sqlquery("SELECT count(*) as nmods FROM general WHERE statType='mod'");
	$res += sqlfetch($req);

	$req = sqlquery("SELECT count(*) as nnames FROM tees");
	$res += sqlfetch($req);

	$req = sqlquery("SELECT count(*) as nservers FROM servers");
	$res += sqlfetch($req);

	$req = sqlquery("SELECT count(*) as nonline FROM tees WHERE lastseen > now() - interval 6 minute");
	$res += sqlfetch($req);

	return array_map("niceDigits",$res);
}

/*
require("slibs/db/db.php");
require("slibs/config_sql.php");
var_dump(generalCounts());
*/
/*
include("slibs/db/db.php");
include("slibs/config_sql.php");
var_dump(getClanName("biopi"));
*/
?>
