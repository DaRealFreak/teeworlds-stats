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
     * @var \PDO|null
     */
    protected $databaseConnection = null;

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
            $this->databaseConnection = new \PDO("mysql:host=$sql_host;dbname=$sql_db", $sql_user, $sql_pass, $pdo_options);
        } catch (\PDOException $e) {
            /** @var Twig $frontendHandler */
            $frontendHandler = GeneralUtility::makeInstance(Twig::class);
            $frontendHandler->renderTemplate("down.twig");
            exit(0);
        }
    }

    /**
     * @param string $sql
     * @return \PDOStatement
     */
    public function sqlPrepare($sql)
    {
        return $this->databaseConnection->prepare($sql);
    }

    /**
     * @param string $sql
     * @param array $values
     * @return \PDOStatement
     */
    public function sqlQuery($sql, $values = array())
    {
        if ($values) {
            $req = $this->databaseConnection->prepare($sql);
            $req->execute($values);
        } else
            $req = $this->databaseConnection->query($sql);

        return $req;
    }

    /**
     * @param \PDOStatement $req
     * @return mixed
     */
    public function sqlFetch($req)
    {
        return $req->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * @param array $search
     * @return array
     */
    public function sqlEqual($search)
    {
        $constraint = "";
        $values = array();
        foreach ($search as $name => $value) {
            $constraint = $constraint . " AND " . $name . "=:" . $name;
            $values[':' . $name] = $value;
        }
        return array($constraint, $values);
    }

    /**
     * @param array $data
     * @param string $table
     * @param string $onDuplicate
     * @return bool
     */
    public function sqlInsert($data, $table, $onDuplicate = "")
    {
        $fieldList = "";
        $indexList = "";
        $aChVal = array();
        foreach ($data as $key => $value) {
            $aChVal[':' . $key] = $value;
            $fieldList = $fieldList . $key . ',';
            $indexList = $indexList . ':' . $key . ',';
        }
        $fieldList = "(" . substr($fieldList, 0, -1) . ")";
        $indexList = "(" . substr($indexList, 0, -1) . ")";

        if (!empty($onDuplicate)) {
            $onDuplicate = " ON DUPLICATE KEY UPDATE " . $onDuplicate;
        }

        $req = $this->databaseConnection->prepare("INSERT INTO " . $table . " " . $fieldList . " VALUES " . $indexList . " " . $onDuplicate . ";");
        return $req->execute($aChVal);
    }

    /**
     * @param array $search
     * @return array
     */
    public function equalClause($search)
    {
        $constraint = "";
        $values = array();
        foreach ($search as $name => $value) {
            $constraint = $constraint . " AND " . $name . "=:" . $name;
            $values[':' . $name] = $value;
        }
        return array($constraint, $values);
    }

    /**
     * @param array $set
     * @return array
     */
    public function setClause($set)
    {
        $constraint = "";
        $values = array();
        foreach ($set as $name => $value) {
            $constraint = $constraint . $name . "=:" . $name . ",";
            $values[':' . $name] = $value;
        }
        $constraint = substr($constraint, 0, -1);
        return array($constraint, $values);
    }
}