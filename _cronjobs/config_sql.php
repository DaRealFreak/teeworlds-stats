<?php

$sql_host = "localhost";
$sql_user = "root";
$sql_pass = "";
$sql_db = "teeworlds";


try {
	$pdo_options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
	$db = new PDO("mysql:host=$sql_host;dbname=$sql_db", $sql_user, $sql_pass, $pdo_options);
}
catch (PDOException $e) {
		include("down.php");
		@mail("contact@teeworlds-stats.info","Teeworlds stats : MySQL error",$e->getMessage());
		exit(0);
}

unset($sql_host, $sql_user, $sql_pass, $sql_db);

?>
