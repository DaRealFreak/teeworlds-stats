<?php


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

echo trender("templates/about.twig", $page);

?>


