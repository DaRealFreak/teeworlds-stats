<?php

$sql_host = "db";
$sql_user = "root";
$sql_pass = "root";
$sql_db = "teestats";

try {
    $pdo_options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
    $db = new PDO("mysql:host=$sql_host;dbname=$sql_db", $sql_user, $sql_pass, $pdo_options);
} catch (PDOException $e) {
    echo trender("templates/down.twig");
    exit(0);
}

unset($sql_host, $sql_user, $sql_pass, $sql_db);


?>
