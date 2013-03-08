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

if(!empty($_SESSION['suggestionsTee']))
	$page["suggestionsTee"] = $_SESSION['suggestionsTee'];
if(!empty($_SESSION['suggestionsClan']))
	$page["suggestionsClan"] = $_SESSION['suggestionsClan'];
if(!empty($_SESSION['suggestionsServer']))
	$page["suggestionsServer"] = $_SESSION['suggestionsServer'];
if(!empty($_SESSION['missingTee']))
	$page["missingTee"] = $_SESSION['missingTee'];
if(!empty($_SESSION['missingClan']))
	$page["missingClan"] = $_SESSION['missingClan'];
if(!empty($_SESSION['missingServer']))
	$page["missingServer"] = $_SESSION['missingServer'];

unset($_SESSION['suggestionsClan'],
		$_SESSION['suggestionsServer'],
		$_SESSION['suggestionsTee'],
		$_SESSION['missingTee'],
		$_SESSION['missingClan'],
		$_SESSION['missingServer']);

echo trender("templates/main.twig", $page);

?>