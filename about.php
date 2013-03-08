<?php


$items = array(
			   array('text' => 'Game statistics',
					 'url'=>myurl("general"),
					 'class' => 'icon-globe'),
			   array('text' => 'Search',
					 'url'=>myurl(""),
					 'class' => 'icon-search'),
			   array('text' => 'About',
					 'url'=>myurl("about"),
					 'class' => 'icon-info-sign')
			   );

$page['navigation'] = $twig->render("views/navigation.twig", array( "items" => $items));

echo trender("templates/about.twig", $page);

?>


