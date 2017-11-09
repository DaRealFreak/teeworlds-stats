<?php

/**
 * @param string $sql
 * @return PDOStatement
 */
function sqlprepare($sql)
{
    global $db;

    return $db->prepare($sql);
}

/**
 * @param string $sql
 * @param array $values
 * @return PDOStatement
 */
function sqlquery($sql, $values = array())
{
    global $db;

    if ($values) {
        $req = $db->prepare($sql);
        $req->execute($values);
    } else
        $req = $db->query($sql);

    return $req;
}

/**
 * @param PDOStatement $req
 * @return mixed
 */
function sqlfetch($req)
{
    return $req->fetch(PDO::FETCH_ASSOC);
}

/**
 * @param array $search
 * @return array
 */
function sqlequal($search)
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
function sqlinsert($data, $table, $onDuplicate = "")
{
    global $db;
    $sChampsListe = "";
    $sIndexListe = "";
    $aChVal = array();
    foreach ($data as $cle => $valeur) {
        $aChVal[':' . $cle] = $valeur;
        $sChampsListe = $sChampsListe . $cle . ',';
        $sIndexListe = $sIndexListe . ':' . $cle . ',';
    }
    $sChampsListe = "(" . substr($sChampsListe, 0, -1) . ")";
    $sIndexListe = "(" . substr($sIndexListe, 0, -1) . ")";

    if (!empty($onDuplicate))
        $onDuplicate = " ON DUPLICATE KEY UPDATE " . $onDuplicate;

    $req = $db->prepare("INSERT INTO " . $table . " " . $sChampsListe . " VALUES " . $sIndexListe . " " . $onDuplicate);
    return $req->execute($aChVal);
}

/**
 * @param array $search
 * @return array
 */
function equalClause($search)
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
 * @return bool
 */
function insert_into($data, $table)
{
    global $db;
    $sChampsListe = "";
    $sIndexListe = "";
    $aChVal = array();
    foreach ($data as $cle => $valeur) {
        $aChVal[':' . $cle] = $valeur;
        $sChampsListe = $sChampsListe . $cle . ',';
        $sIndexListe = $sIndexListe . ':' . $cle . ',';
    }
    $sChampsListe = "(" . substr($sChampsListe, 0, -1) . ")";
    $sIndexListe = "(" . substr($sIndexListe, 0, -1) . ")";

    $req = $db->prepare("INSERT INTO " . $table . " " . $sChampsListe . " VALUES " . $sIndexListe . ";");
    return $req->execute($aChVal);
}

/**
 * @param array $set
 * @return array
 */
function setClause($set)
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

