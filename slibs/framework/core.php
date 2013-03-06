 <?php

function trender($f, $params = array()) {
	global $twig;

	$params['basedir'] = dirname($_SERVER['PHP_SELF']);
	if(strlen($params['basedir']) > 1)
		$params['basedir'] = $params['basedir'] . "/";
	$params = $params + include_clibs("clibs");

	return $twig->render($f,$params);
 }

function include_clibs($dir) {
	$res = array("js" => array(), "css" => array());

	if(file_exists($dir . "/dependencies.conf")) {
		foreach(explode("\n", file_get_contents($dir . "/dependencies.conf")) as $fd) {
			$fd = trim($fd);
			if(file_exists("$dir/$fd"))
				if(is_dir("$dir/$fd") && !empty($fd)) {
					$subres = include_clibs("$dir/$fd");
					$res['css'] = array_merge($res['css'], $subres['css']);
					$res['js'] = array_merge($res['js'], $subres['js']);
				} elseif(is_file("$dir/$fd")) {
					if(preg_match("/.*\.css/",$fd)) {
						$res['css'][] = "$dir/$fd";
					}
					elseif(preg_match("/.*\.js/",$fd)) {
						$res['js'][] = "$dir/$fd";
					}
				}
		}
	} else {
		$res['js'] = array_merge($res['js'], glob("$dir/*.js"));
		$res['css'] = array_merge($res['css'], glob("$dir/*.css"));
		foreach(glob("$dir/*", GLOB_ONLYDIR) as $d) {
			$subres = include_clibs($d);
			$res['css'] = array_merge($res['css'], $subres['css']);
			$res['js'] = array_merge($res['js'], $subres['js']);
		}
	}

	return $res;
}

/**
 *	Validates a user-filled form.
 *	Returns an array of errors.
 *
 *	$rules = array( $field => array( $regexp => $error, ... ), ...)
 *	$mandatory = array( $field => $error, ...)
 */
function formValidation($gp, $rules = array(), $mandatory = array()) {
	$errors = array();

	foreach($mandatory as $field => $error)
		if(empty($gp[$field]))
			$errors[] = $error;

	foreach($rules as $field => $rulz)
		foreach($rulz as $regexp => $error)
			if(!empty($gp[$field]))
				if(!preg_match($regexp, $gp[$field]))
					$errors[] = $error;

	return $errors;
}

/**
 *	Returns a well formatted url
 *		like page?p1=v1&p2=v2
 *		or   index.php?p1=v1&p2=v2
 */
function myurl($page = "", $params = array() , $usePath = true) {
	$url = "";

	if(!$usePath) {
		$params['p'] = $page;
		$url = "index.php?" . http_build_query($params);
	} else {
		$url = $page;
		if(!empty($params))
			$url = $url . "?" . http_build_query($params);
	}

	return $url;
}

/**
 * Check whether a form has been submitted
 */
function frmsubmitted($frm) {
	foreach($frm as $field)
		if(!isset($_GET[$field]) && !isset($_POST[$field]))
			return false;

	return true;
}

/**
 * Saves a form in the session
 */
function frmsave($frm) {
	$form = array();
	foreach($frm as $field) {
		if(isset($_GET[$field]))
			$form[$field] = $_GET[$field];
		if(isset($_POST[$field]))
			$form[$field] = $_POST[$field];
	}
	if(!empty($form))
		$_SESSION[frmname($frm)] = $form;
}

/**
 *	Returns a form saved in the session
 */
function frmrestore($frm) {
	$name = frmname($frm);
	return isset($_SESSION[$name]) ? $_SESSION[$name] : array();
}

/**
 *	Returns the submitted form
 */
function frmget($frm) {
	$res = array();
	foreach($frm as $field) {
		if(isset($_GET[$field]))
			$res[$field] = $_GET[$field];
		if(isset($_POST[$field]))
			$res[$field] = $_POST[$field];
	}
	return $res;
}

/**
 * Returns the name of the form as stored in the session
 */
function frmname($frm) {
	$name = "";
	foreach($frm as $field)
		$name = $name . " " . $field;
	return $name;
}

/**
 * Removes the given fields if empty
 */
function frmtidy($form, $fields) {
	$res = $form;
	foreach($fields as $field)
		if(empty($res[$field]))
			unset($res[$field]);
	return $res;
}

/** Calls header("Location: $url"); exit(0); */
function redirect($url) {
	header("Location: $url");
	exit(0);
}
?>