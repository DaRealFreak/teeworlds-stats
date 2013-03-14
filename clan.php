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
					 'class' => 'icon-globe'),
			   array('text' => 'Search',
					 'url'=>myurl(""),
					 'class' => 'icon-search')
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

	$items[] = array('text' => 'Account', 'url' => myurl("account"), 'class' => 'icon-pencil');
}

$items[] = array('text' => 'About', 'url'=>myurl("about"), 'class' => 'icon-info-sign');

$page['navigation'] = $twig->render("views/navigation.twig", array( "items" => $items));

/*		SELECTING CLAN INFO TO DISPLAY		*/


$clanDetails = getClanDetails($clan);

if(!empty($clanDetails["clantxt"]))
	if(strip_tags($clanDetails["clantxt"]) != "")
		$page["clantxt"] = integrateYoutubeVideos($clanDetails["clantxt"]);

if($clanDetails["clanmods"] == 1) {
	$hist_mods = gethisto("clan",$clan,"mod");
	$page['mods'] = $twig->render("views/pie.twig", array("histogram" => $hist_mods,
																   "name" => "$clan mods",
																   "id" => "piemods"));
}

if($clanDetails["clanmaps"] == 1) {
	$hist_maps = gethisto("clan",$clan,"map");
	$page['maps'] = $twig->render("views/pie.twig", array("histogram" => $hist_maps,
																   "name" => "$clan maps",
																   "id" => "piemaps"));
}


if($clanDetails["clancountries"] == 1) {
	$hist_countries = gethisto("clan",$clan,"country");
	$page['countries'] = $twig->render("views/pie.twig", array("histogram" => $hist_countries,
																   "name" => "$clan countries",
																   "id" => "piecountries"));
}

if($clanDetails["clanhours"] == 1) {
	$hhours = gethours("clan",$clan);
	$page['hours'] = $twig->render("views/bars.twig", array("histogram" => $hhours,
																   "name" => "$clan online time per hour",
																   "id" => "piehours"));
}

if($clanDetails["clandays"] == 1) {
	$hdays = getdays("clan", $clan);
	$page['days'] = $twig->render("views/bars.twig", array("histogram" => $hdays,
																   "name" => "$clan online time per day (Monday to Sunday)",
																   "id" => "piedays"));
}

if($clanDetails["clanplayers"] == 1) {
	$players = getClanPlayers($clan);
	$page['players'] = $twig->render("views/playerlist.twig", array("title" => "$clan players",
																"players" => $players));
}


$page['title'] = "$clan statistics on Teeworlds";
$page['clan'] = $clan;


echo trender("templates/clan.twig", $page);
