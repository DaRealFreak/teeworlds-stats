<?php
/**
 * Created by PhpStorm.
 * User: Steffen
 * Date: 09/11/2017
 * Time: 14:25
 */

namespace TwStats\Core\Backend;


use TwStats\Core\Frontend\Twig;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Core\Utility\SingletonInterface;

class Database implements SingletonInterface
{
    /**
     * Database constructor.
     */
    public function __construct()
    {
        $sql_host = getenv('TWSTATS_DB_HOST');
        $sql_user = getenv('TWSTATS_DB_USER');
        $sql_pass = getenv('TWSTATS_DB_PASS');
        $sql_db = getenv('TWSTATS_DB');

        try {
            $pdo_options[\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;
            $GLOBALS['DB'] = new \PDO("mysql:host=$sql_host;dbname=$sql_db", $sql_user, $sql_pass, $pdo_options);
        } catch (\PDOException $e) {
            /** @var Twig $frontendHandler */
            $frontendHandler = GeneralUtility::makeInstance(Twig::class);
            $frontendHandler->renderTemplate("down.twig");
            exit(0);
        }

        unset($sql_host, $sql_user, $sql_pass, $sql_db);
    }
}