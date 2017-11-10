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

$teeDetails = getTeeDetails($tee);
if(!empty($teeDetails["clan"]))
	$player['clan'] = $teeDetails["clan"];

$page['title'] = "Teeworlds statistics - $tee";

$page['tee'] = $tee;
if(!empty($player['clan']))
	$page['clan'] = $player['clan'];
if(!empty($player['country']))
	$page['country'] = $player['country'];

$items = array(
			   array('text' => 'Game statistics',
					 'url'=>myurl("general"),
					 'class' => 'icon-globe'),
			   array('text' => 'Search',
					 'url'=>myurl(""),
					 'class' => 'icon-search'),
			   );

$user = getFacebookID();
if($user) {
	$page['logged'] = true;

	$account = getAccountDetails($user);
	if(!empty($account["tee"]))
		$items[] = array('text' => $account['tee'],
						 'url'=>myurl("tee",array("n" => $account['tee'])),
						 'class' => 'icon-user');
	if(!empty($account["clan"]))
		$items[] = array('text' => $account['clan'],
						 'url'=>myurl("clan",array("n" => $account['clan'])),
						 'class' => 'icon-home');

}

if(!empty($player['clan']))
	$items[] = array('text' => $player['clan'],
					 'url'=>myurl("clan",array("n" => $player['clan'])),
					 'class' => 'icon-home');

if($user)
	$items[] = array('text' => 'Account', 'url' => myurl("account"), 'class' => 'icon-pencil');


$items[] = array('text' => 'About',
				 'url'=>myurl("about"),
				 'class' => 'icon-info-sign');

$page['navigation'] = $twig->render("views/navigation.twig", array( "items" => $items));

if(!empty($teeDetails["teetxt"]))
	if(strip_tags($teeDetails["teetxt"]) != "")
		$page["teetxt"] = integrateYoutubeVideos($teeDetails["teetxt"]);

if($teeDetails["teemods"] == 1) {
	$hist_mods = gethisto("tee",$tee,"mod");
	$page['mods'] = $twig->render("views/pie.twig",
									  array("id" => "piemods",
											"name" => "$tee's favorite mods",
											"histogram" => $hist_mods));
}

if($teeDetails["teemaps"] == 1) {
	$hist_maps = gethisto("tee",$tee,"map");
	$page['maps'] = $twig->render("views/pie.twig",
									  array("id" => "piemaps",
											"name" => "$tee's favorite maps",
											"histogram" => $hist_maps));
}

if($teeDetails["teehours"] == 1) {
	$histhours = gethours("tee",$tee);
	$page['hours'] = $twig->render("views/bars.twig",
									  array("id" => "hourbars",
											"name" => "$tee's online time per hour",
											"histogram" => $histhours));
}

if($teeDetails["teedays"] == 1) {
	$histdays = getdays("tee", $tee);
	$page['days'] = $twig->render("views/bars.twig",
									array("id" => "daybars",
										"name" => "$tee's online time per day (Monday to Sunday)",
										"histogram" => $histdays));
}


echo trender("templates/tee.twig",$page);

?>
