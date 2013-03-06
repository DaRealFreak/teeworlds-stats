<?php

$server = empty($uri[1]) ? '' : $uri[1];
$server = empty($gp['n']) ? $server : $gp['n'];

if(empty($server))
	redirect(".");

$name = getServerName($server);

if(!$name) {
	$_SESSION['suggestionsServer'] = getSimilarData("server", $server);
	$_SESSION['missingServer'] = true;
	redirect(".");
}
$server = $name;

$hist_maps = gethisto("server",$server,"map");
$hist_countries = gethisto("server",$server,"country");

$hhours = gethours("server",$server);
$hdays = getdays("server", $server);


$items = array(
			   array('text' => 'Game statistics',
					 'url'=>myurl("general"),
					 'class' => 'graphs'),
			   array('text' => 'Search',
					 'url'=>myurl(""),
					 'class' => 'gallery'),
			   array('text' => 'About',
					 'url'=>myurl("about"),
					 'class' => 'typo')
			   );

$page['navigation'] = $twig->render("views/navigation.twig", array( "items" => $items));

$players = getServerPlayers($server);

$page['title'] = "$server statistics on Teeworlds";
$page['server'] = $server;

$page['players'] = $twig->render("views/playerlist.twig", array("title" => "Playing tees",
															"players" => $players));

$page['countries'] = $twig->render("views/pie.twig", array("histogram" => $hist_countries,
															   "name" => "Most playing countries",
															   "id" => "piecountries"));
$page['maps'] = $twig->render("views/pie.twig", array("histogram" => $hist_maps,
															   "name" => "Most played maps	",
															   "id" => "piemaps"));
$page['hours'] = $twig->render("views/bars.twig", array("histogram" => $hhours,
															   "name" => "Online time per hour",
															   "id" => "piehours"));
$page['days'] = $twig->render("views/bars.twig", array("histogram" => $hdays,
															   "name" => "Online time per day (Monday to Sunday)",
															   "id" => "piedays"));

echo trender("templates/server.twig", $page);

?>
