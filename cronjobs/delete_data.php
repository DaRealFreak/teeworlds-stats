<?php

include(dirname(__FILE__) . "./config_sql.php");

$curdate = date('Y-m-d H:i:s');
$maxdate = date("Y-m-d H:i:s", strtotime($curdate) - 4*24*3600);

$req = $db->prepare("DELETE FROM chdata_clan WHERE curdate<=?");
$req->execute(array($maxdate));
$req = $db->prepare("DELETE FROM chdata_playername WHERE curdate<=?");
$req->execute(array($maxdate));
$req = $db->prepare("DELETE FROM chdata_servername WHERE curdate<=?");
$req->execute(array($maxdate));

$req = $db->prepare("DELETE FROM cache_clan WHERE timestamp<=?");
$req->execute(array(date("Y-m-d H:i:s",time() - 9*60)));
$req = $db->prepare("DELETE FROM cache_player WHERE timestamp<=?");
$req->execute(array(date("Y-m-d H:i:s",time() - 9*60)));
$req = $db->prepare("DELETE FROM cache_server WHERE timestamp<=?");
$req->execute(array(date("Y-m-d H:i:s",time() - 9*60)));

?>
