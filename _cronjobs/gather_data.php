<?php

$timeA = time();

ini_set("max_execution_time", 10*60);

$curdate = date('Y-m-d H:i:s');

include(dirname(__FILE__) . "/TwRequest.php");

$twdata = new TwRequest();

//$twdata->loadServersFromMasterservers();


include(dirname(__FILE__) . "/../slibs/config_sql.php");

require(dirname(__FILE__) . "/db.php");

$req = $db->query("SELECT * FROM servers");
$servers = array();

while($data = $req->fetch(PDO::FETCH_ASSOC))
	$servers[] = array(0 => $data['address'], 1 => $data['port'], 2 => 6);

$twdata->addServers($servers);
$twdata->loadServerInfo();

$data = $twdata->getServers();

$timeB = time();
$dT = $timeB - $timeA;

echo "Rassembler les donnees des serveurs : $dT s<br/>";

$timeB = time();

$general = array();
foreach($data as $servinfo){
	if(isset($servinfo["players"])) {
		foreach($servinfo['players'] as $player) {
			if($player["name"] === '(connecting)')
				continue;

			$tees['tee'] = $player["name"];
			$tees['server'] = $servinfo["name"];
			$tees['clan'] = $player["clan"];
			$tees['firstseen'] = $curdate;
			$tees['lastseen'] = $curdate;

			$req = $db->prepare("INSERT INTO tees (tee,server,clan,firstseen,lastseen) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE server=?, clan=?, lastseen=?");
			$req->execute(array($tees['tee'],$tees['server'],$tees['clan'],$tees['firstseen'],$tees['lastseen'],$tees['server'],$tees['clan'],$tees['lastseen']));

			$tee = $player["name"];
			$clan = $player["clan"];
			$server = $servinfo["name"];

			$stat['mod'] = $servinfo["gametype"];
			$stat['map'] = $servinfo["map"];
			$stat['country'] = $twdata->getCountryName($player["country"]);
			$stat['hour'] = date('H');
			$stat['day'] = date('D');

			$req = $db->prepare("INSERT INTO data VALUES (?,?,?,?,1)
								ON DUPLICATE KEY UPDATE count=count+1");

			if(!in_array(strtolower($tee),array('(connecting)','nameless tee')))
			foreach(array('mod','map','hour','day') as $st)
				$req->execute(array($tee,'tee',$stat[$st],$st));

			if(!empty($clan))
			foreach(array('mod','map','hour','day','country') as $st)
				$req->execute(array($clan,'clan',$stat[$st],$st));

			foreach(array('map','hour','day','country') as $st)
				$req->execute(array($server,'server',$stat[$st],$st));

			if(!isset($general['mod'][$stat['mod']]))
				$general['mod'][$stat['mod']] = 1;
			else
				$general['mod'][$stat['mod']] += 1;

			if(!isset($general['country'][$stat['country']]))
				$general['country'][$stat['country']] = 1;
			else
				$general['country'][$stat['country']] += 1;
		}
	}
}


foreach($general['mod'] as $mod => $count)
	sqlinsert(array('stat' => $mod, 'statType' => 'mod', 'count' => $count),
				"general",
				"count = count + $count");

foreach($general['country'] as $cty => $count)
	sqlinsert(array('stat' => $cty, 'statType' => 'country', 'count' => $count),
				"general",
				"count = count + $count");

$timeC = time();
$dT = $timeC - $timeB;

//echo "Inserts dans la BDD : $dT s<br/>";

exit(0);
include(dirname(__FILE__) . "/config_sql.php");

foreach($data as $servinfo){
	if(isset($servinfo["players"])) {
		foreach($servinfo['players'] as $player) {
			$rec = array();
			$rec["curdate"] = $curdate;
			$rec["playername"] = $player["name"];
			$rec["clan"] = $player["clan"];
			$rec["country"] = $twdata->getCountryName($player["country"]);
			$rec["score"] = $player["score"];
			$rec["servername"] = $servinfo["name"];
			$rec["map"] = $servinfo["map"];
			$rec["gametype"] = $servinfo["gametype"];

			if($player["ingame"] && $rec["playername"] != "(connecting)") {
				//insert_into($rec,"chdata");
				unset($rec["score"]);
				sqlinsert($rec,"chdata_clan");
				sqlinsert($rec,"chdata_playername");
				sqlinsert($rec,"chdata_servername");
			}
		}
	}
}

$timeC = time();
$dT = $timeC - $timeB;

//echo "Inserts dans la BDD : $dT s<br/>";

?>
