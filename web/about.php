<?php


$items = array(
			   array('text' => 'Game statistics',
					 'url'=>myurl("general"),
					 'class' => 'icon-globe'),
			   array('text' => 'Search',
					 'url'=>myurl(""),
					 'class' => 'icon-search')
			   );

$user = getFacebookID();
$page['logged'] = true;
if($user) {
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

echo trender("templates/about.twig", $page);

?>


