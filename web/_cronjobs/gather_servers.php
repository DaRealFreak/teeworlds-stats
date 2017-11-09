<?php
$timeA = time();

ini_set("max_execution_time", 10 * 60);
$curdate = date('Y-m-d H:i:s');

include(dirname(__FILE__) . "/TwRequest.php");

$twdata = new TwRequest();

$twdata->loadServersFromMasterservers();

$servers = $twdata->getServers();

include(dirname(__FILE__) . "/../slibs/config_sql.php");

$db->query("TRUNCATE `servers`");

$req = $db->prepare("INSERT INTO `servers` (`address`, `port`, `version`) VALUES (?, ?, ?)");

$count = 0;
foreach ($servers as $server) {
    $req->execute(array($server[0], $server[1], $server[2]));
    ++$count;
}

echo sprintf("Fetched %d servers from the master servers", $count);

