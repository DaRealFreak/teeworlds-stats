<?php

$user = getFacebookID();

if($user)
	$page['logged'] = true;
else
	$page['logged'] = false;


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
else
	$page['logged'] = false;

$items[] = array('text' => 'About', 'url'=>myurl("about"), 'class' => 'icon-info-sign');

$page['navigation'] = $twig->render("views/navigation.twig", array("items" => $items));


// Stats
$hmod = getglobalhisto("mod",13);
$hcountry = getglobalhisto("country",13);


$page['mods'] = $twig->render("views/pie.twig",
								  array("id" => "piemods",
										"name" => "Most played mods",
										"histogram" => $hmod));

$page['countries'] = $twig->render("views/pie.twig",
								  array("id" => "piecountries",
										"name" => "Most playing countries",
										"histogram" => $hcountry));

$page += generalCounts();

echo trender("templates/general.twig", $page);

?>