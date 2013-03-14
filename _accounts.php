<?php

function getFacebookID($verify = false) {

	global $facebook;
	// See if there is a user from a cookie
	$user = $facebook->getUser();

	if ($user && $verify) {
	  try {
		// Proceed knowing you have a logged in user who's authenticated.
		$user_profile = $facebook->api('/me');
	  } catch (FacebookApiException $e) {
		$user = 0;
	  }
	}

	$formDetails = array("tee", "teetxt", "teemods", "teemaps", "teehours", "teedays",
						"clan", "clantxt", "clanmods", "clanmaps", "clancountries",
						"clanhours", "clandays", "clanplayers");

	if(!$user)
		frmremove($formDetails);

	return $user;
}

function updateAccountDetails($fields, $facebookid) {
	$fields["facebookid"] = $facebookid;

	global $purifier;
	$fields["teetxt"] = $purifier->purify($fields["teetxt"]);
	$fields["clantxt"] = $purifier->purify($fields["clantxt"]);

	if($fields["tee"] != "")
			$fields["tee"] = getTeeName($fields["tee"]);
	if($fields["clan"] != "")
			$fields["clan"] = getClanName($fields["clan"]);

	$setStr = "";
	foreach($fields as $field => $val) {
		if($field != "facebookid")
			$setStr = $setStr . ",$field=:$field";
	}
	$setStr = substr($setStr, 1);

	sqlinsert($fields,"accounts", $setStr);
}

function getAccountDetails($facebookid) {
	if($frm = frmrestore(array("tee", "teetxt", "teemods", "teemaps", "teehours", "teedays",
						"clan", "clantxt", "clanmods", "clanmaps", "clancountries",
						"clanhours", "clandays", "clanplayers")))
		return $frm;

	$req = sqlquery("SELECT * FROM accounts WHERE facebookid = ?", array($facebookid));
	return sqlfetch($req);
}

function getTeeDetails($tee) {
	$req = sqlquery("SELECT * FROM accounts WHERE tee = ?", array($tee));
	if($res = sqlfetch($req))
		return $res;
	return array("teetxt" => "", "teemods" => 1, "teemaps" => 1, "teehours" => 1, "teedays" => 1);
}

function getClanDetails($clan) {
	$req = sqlquery("SELECT * FROM accounts WHERE clan = ?", array($clan));
	if($res = sqlfetch($req))
		return $res;
	return array("clantxt" => "", "clanmods" => 1, "clanmaps" => 1, "clancountries" => 1,
					"clandays" => 1, "clanhours" => 1, "clanplayers" => 1);
}

function checkNameAvailability($form, $facebookid) {
	$res = array();

	if($form["tee"] != "") {
		$req = sqlquery("SELECT * FROM accounts WHERE tee = ? AND facebookid <> ?",
								array($form["tee"], $facebookid));
		if(sqlfetch($req))
			$res[] = "This nickname is already taken";

		if($form["tee"] != "" && !getPlayer($form["tee"]))
			$res[] = "This tee does not exist. You must specify an existing tee name.";
	}

	if($form["clan"] != "") {
		$req = sqlquery("SELECT * FROM accounts WHERE clan = ? AND facebookid <> ?",
								array($form["clan"], $facebookid));
		if(sqlfetch($req))
			$res[] = "This clan is already taken";


		if(!getClanName($form["clan"]))
			$res[] = "This clan does not exist. You must specify an existing clan.";
	}

	return $res;
}

function integrateYoutubeVideos($text) {
	$replacement = "<iframe width='560' height='315'
					src='http://www.youtube.com/embed/\2'
					frameborder='0' allowfullscreen></iframe>";
	$find = '/http:\/\/www\.youtube\.com\/watch\?(.*?)v=([a-zA-Z0-9_\-]+)(\S*)/i';;
	$res = preg_replace($find, $replacement,$text);

	$pattern = '/http:\/\/www\.youtube\.com\/watch\?(.*?)v=([a-zA-Z0-9_\-]+)/i';
	$replace = '<iframe title="YouTube" class="youtube" type="text/html" width="560" height="315" src="http://www.youtube.com/embed/$2" frameborder="0" allowFullScreen></iframe>';
	$string = preg_replace($pattern, $replace, $text);

	return $string;
}
?>