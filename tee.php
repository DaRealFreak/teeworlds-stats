<?php

$tee = empty($uri[1]) ? '' : $uri[1];
$tee = empty($gp['n']) ? $tee : $gp['n'];

if(empty($tee))
	redirect(".");

$player = getPlayer($tee);

if(!$player) {
	$_SESSION['suggestionsTee'] = getSimilarData("tee", $tee);
	$_SESSION['missingTee'] = true;
	redirect(".");
}

$tee = $player['tee'];

$hist_mods = gethisto("tee",$tee,"mod");
$hist_maps = gethisto("tee",$tee,"map");

$histhours = gethours("tee",$tee);
$histdays = getdays("tee", $tee);

$page['title'] = "Teeworlds statistics - $tee";

$page['tee'] = $tee;
if(!empty($player['clan']))
	$page['clan'] = $player['clan'];
if(!empty($player['country']))
	$page['country'] = $player['country'];

$items = array(
			   array('text' => 'Game statistics',
					 'url'=>myurl("general"),
					 'class' => 'graphs'),
			   array('text' => 'Search',
					 'url'=>myurl(""),
					 'class' => 'gallery'),
			   );

if(!empty($player['clan']))
	$items[] = array('text' => 'Clan : ' . $player['clan'],
					 'url'=>myurl("clan",array("n" => $player['clan'])),
					 'class' => 'contacts');

$items[] = array('text' => 'About',
				 'url'=>myurl("about"),
				 'class' => 'typo');

$page['navigation'] = $twig->render("views/navigation.twig", array( "items" => $items));

$page['mods'] = $twig->render("views/pie.twig",
								  array("id" => "piemods",
										"name" => "$tee's favorite mods",
										"histogram" => $hist_mods));

$page['maps'] = $twig->render("views/pie.twig",
								  array("id" => "piemaps",
										"name" => "$tee's favorite maps",
										"histogram" => $hist_maps));

$page['hours'] = $twig->render("views/bars.twig",
								  array("id" => "hourbars",
										"name" => "$tee's online time per hour",
										"histogram" => $histhours));

$page['days'] = $twig->render("views/bars.twig",
								array("id" => "daybars",
									"name" => "$tee's online time per day (Monday to Sunday)",
									"histogram" => $histdays));

echo trender("templates/tee.twig",$page);

?>
