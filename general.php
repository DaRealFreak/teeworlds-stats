<?php

$hmod = getglobalhisto("mod",13);
$hcountry = getglobalhisto("country",13);

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