<?php

$items = array(
			   array('text' => 'Game statistics',
					 'url'=>myurl("general"),
					 'class' => 'icon-globe'),
			   array('text' => 'Search',
					 'url'=>myurl(""),
					 'class' => 'icon-search')
			   );

$user = getFacebookID(true);

$formDetails = array("tee", "teetxt", "teemods", "teemaps", "teehours", "teedays",
					"clan", "clantxt", "clanmods", "clanmaps", "clancountries",
					"clanhours", "clandays", "clanplayers");

if($user) {
	$page['logged'] = true;

	if(frmsubmitted($formDetails)) {
		var_dump($formDetails);
		if(!$err = checkNameAvailability(frmget($formDetails),$user)) {
			updateAccountDetails(frmget($formDetails),$user);
			$_SESSION['success'] = true;
		}
		else {
			$_SESSION['success'] = false;
			$_SESSION['errors'] = $err;
		}

		redirect("index.php?p=account");
	}

	if(!empty($_SESSION['success'])) {
		$page['success'] = $_SESSION['success'];
		unset($_SESSION['success']);
	}
	if(!empty($_SESSION['errors'])) {
		$page['errors'] = $_SESSION['errors'];
		unset($_SESSION['errors']);
	}

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

	if($account)
		foreach($account as $key => $val)
			$page[$key] = $val;
}
else
	$page['logged'] = false;

$items[] = array('text' => 'About', 'url'=>myurl("about"), 'class' => 'icon-info-sign');

$page['navigation'] = $twig->render("views/navigation.twig", array("items" => $items));

echo trender("templates/account.twig", $page);

?>

