<?php
/**
 * Created by PhpStorm.
 * User: Steffen
 * Date: 09/11/2017
 * Time: 14:25
 */

namespace TwStats\Core\Backend;


class Database implements SingletonInterface
{
    /**
     * Database constructor.
     */
    public function __construct()
    {
        // ToDo: extract into .env file
        $sql_host = "db";
        $sql_user = "root";
        $sql_pass = "root";
        $sql_db = "teestats";

        try {
            $pdo_options[\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;
            $GLOBALS['DB'] = new \PDO("mysql:host=$sql_host;dbname=$sql_db", $sql_user, $sql_pass, $pdo_options);
        } catch (\PDOException $e) {
            // ToDo: template paths and twig configuration
            echo trender("templates/down.twig");
            exit(0);
        }

        unset($sql_host, $sql_user, $sql_pass, $sql_db);
    }
}