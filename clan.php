<?php

$clan = empty($uri[1]) ? '' : $uri[1];
$clan = empty($gp['n']) ? $clan : $gp['n'];

if(empty($clan))
	redirect(".");

$name = getClanName($clan);
if(!$name) {
	$_SESSION['suggestionsClan'] = getSimilarData("clan", $clan);
	$_SESSION['missingClan'] = true;
	redirect(".");
}
$clan = $name;

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

$hist_mods = gethisto("clan",$clan,"mod");
$hist_maps = gethisto("clan",$clan,"map");
$hist_countries = gethisto("clan",$clan,"country");

$players = getClanPlayers($clan);

$hhours = gethours("clan",$clan);
$hdays = getdays("clan", $clan);


$page['title'] = "$clan statistics on Teeworlds";
$page['clan'] = $clan;

$page['players'] = $twig->render("views/playerlist.twig", array("title" => "$clan players",
															"players" => $players));

$page['countries'] = $twig->render("views/pie.twig", array("histogram" => $hist_countries,
															   "name" => "$clan countries",
															   "id" => "piecountries"));
$page['mods'] = $twig->render("views/pie.twig", array("histogram" => $hist_mods,
															   "name" => "$clan mods",
															   "id" => "piemods"));
$page['maps'] = $twig->render("views/pie.twig", array("histogram" => $hist_maps,
															   "name" => "$clan maps",
															   "id" => "piemaps"));
$page['hours'] = $twig->render("views/bars.twig", array("histogram" => $hhours,
															   "name" => "$clan online time per hour",
															   "id" => "piehours"));
$page['days'] = $twig->render("views/bars.twig", array("histogram" => $hdays,
															   "name" => "$clan online time per day (Monday to Sunday)",
															   "id" => "piedays"));

echo trender("templates/clan.twig", $page);
