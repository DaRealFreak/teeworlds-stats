<?php

function sqlprepare($sql) {
	global $db;

	return $db->prepare($sql);
}

function sqlquery($sql, $values = array()) {
	global $db;

	if($values) {
		$req = $db->prepare($sql);
		$req->execute($values);
	}
	else
		$req = $db->query($sql);

	return $req;
}

function sqlfetch($req) {
	return $req->fetch(PDO::FETCH_ASSOC);
}

function sqlequal($search) {
	$constraint = "";
	$values = array(); $i = 0;
	foreach($search as $name => $value) {
		$constraint = $constraint . " AND " . $name . "=:" . $name;
		$values[':'.$name] = $value;
	}
	return array($constraint, $values);
}

function sqlinsert($data, $table, $onduplicate="") {
	global $db;
	$sChampsListe = "";
	$sIndexListe = "";
	$aChVal = array();
	foreach($data as $cle => $valeur) {
		$aChVal[':'.$cle] = $valeur;
		$sChampsListe = $sChampsListe . $cle . ',';
		$sIndexListe = $sIndexListe  . ':' . $cle . ',';
	}
	$sChampsListe = "(" . substr($sChampsListe,0,-1) . ")";
	$sIndexListe = "(" . substr($sIndexListe,0,-1) . ")";

	if(!empty($onduplicate))
		$onduplicate = " ON DUPLICATE KEY UPDATE " . $onduplicate;

	$req = $db->prepare("INSERT INTO ".$table." ".$sChampsListe." VALUES ".$sIndexListe." ".$onduplicate);
	return $req->execute($aChVal);
}


function equalClause($search) {
	$constraint = "";
	$values = array(); $i = 0;
	foreach($search as $name => $value) {
		$constraint = $constraint . " AND " . $name . "=:" . $name;
		$values[':'.$name] = $value;
	}
	return array($constraint, $values);
}

function insert_into($data, $table) {
	global $db;
	$sChampsListe = "";
	$sIndexListe = "";
	$aChVal = array();
	foreach($data as $cle => $valeur) {
		$aChVal[':'.$cle] = $valeur;
		$sChampsListe = $sChampsListe . $cle . ',';
		$sIndexListe = $sIndexListe  . ':' . $cle . ',';
	}
	$sChampsListe = "(" . substr($sChampsListe,0,-1) . ")";
	$sIndexListe = "(" . substr($sIndexListe,0,-1) . ")";

	$req = $db->prepare("INSERT INTO ".$table." ".$sChampsListe." VALUES ".$sIndexListe.";");
	return $req->execute($aChVal);
}


function setClause($set) {
	$constraint = "";
	$values = array(); $i = 0;
	foreach($set as $name => $value) {
		$constraint = $constraint . $name . "=:" . $name .",";
		$values[':'.$name] = $value;
	}
	$constraint = substr($constraint,0,-1);
	return array($constraint, $values);
}

?>
