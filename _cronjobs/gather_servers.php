<?php

$timeA = time();

ini_set("max_execution_time", 10*60);
$curdate = date('Y-m-d H:i:s');

include(dirname(__FILE__) . "/TwRequest.php");

$twdata = new TwRequest();

$twdata->loadServersFromMasterservers();

$servers = $twdata->getServers();

include(dirname(__FILE__) . "/../slibs/config_sql.php");

$db->query("DELETE FROM servers");

$req = $db->prepare("INSERT INTO servers VALUES (?,?)");

foreach($servers as $server)
	$req->execute(array($server[0],$server[1]));


?>
