<?php

session_start();

foreach(glob("slibs/*/*.php") as $file)
	require_once($file);

foreach(glob("slibs/*.php") as $file)
	require_once($file);

foreach(glob("_*.php") as $file)
	require_once($file);

$files = glob("*.php");

$p = "";
$uri = array();

if(!empty($_GET['uri'])) {
	$uri = explode("/",$_GET['uri']);
	if(!empty($uri[0]))
		$p = $uri[0];
}

if(empty($p) && !empty($_GET['p']))
	$p = $_GET['p'];
if(empty($p) && !empty($_POST['p']))
	$p = $_POST['p'];

unset($_GET['p']);
$gp = array_merge($_GET, $_POST);

if(!empty($p) && in_array($p.".php",$files))
	require_once($p.".php");
elseif(in_array("main.php",$files))
	require_once("main.php");

?>
