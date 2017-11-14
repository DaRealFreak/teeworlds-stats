<?php

namespace TwStats\Ext\Database;


use TwStats\Core\Backend\AbstractRepository;
use TwStats\Core\Utility\GeneralUtility;

class AccountRepository extends AbstractRepository
{
    /**
     * @var StatRepository
     */
    protected $statRepository = null;

    /**
     * class entrypoint
     */
    public function initialize()
    {
        $this->statRepository = GeneralUtility::makeInstance(StatRepository::class);
    }

    /**
     * @param $tee
     * @return array|mixed
     */
    public function getTeeDetails($tee)
    {
        $req = $this->databaseConnection->sqlQuery("SELECT * FROM accounts WHERE tee = ?", array($tee));
        if ($res = $this->databaseConnection->sqlFetch($req)) {
            return $res;
        }
        return array("teetxt" => "", "teemods" => 1, "teemaps" => 1, "teehours" => 1, "teedays" => 1);
    }

    /**
     * @param $clan
     * @return array|mixed
     */
    public function getClanDetails($clan)
    {
        $req = $this->databaseConnection->sqlQuery("SELECT * FROM accounts WHERE clan = ?", array($clan));
        if ($res = $this->databaseConnection->sqlFetch($req)) {
            return $res;
        }
        return array("clantxt" => "", "clanmods" => 1, "clanmaps" => 1, "clancountries" => 1,
            "clandays" => 1, "clanhours" => 1, "clanplayers" => 1);
    }

    /**
     * @param $form
     * @param $facebookId
     * @return array
     */
    public function checkNameAvailability($form, $facebookId)
    {
        $res = array();

        if ($form["tee"] != "") {
            $req = $this->databaseConnection->sqlQuery("SELECT * FROM accounts WHERE tee = ? AND facebookid <> ?",
                array($form["tee"], $facebookId));
            if ($this->databaseConnection->sqlFetch($req)) {
                $res[] = "This nickname is already taken";
            }

            if ($form["tee"] != "" && !$this->statRepository->getPlayer($form["tee"])) {
                $res[] = "This tee does not exist. You must specify an existing tee name.";
            }
        }

        if ($form["clan"] != "") {
            $req = $this->databaseConnection->sqlQuery("SELECT * FROM accounts WHERE clan = ? AND facebookid <> ?",
                array($form["clan"], $facebookId));
            if ($this->databaseConnection->sqlFetch($req)) {
                $res[] = "This clan is already taken";
            }

            if (!$this->statRepository->getClanName($form["clan"])) {
                $res[] = "This clan does not exist. You must specify an existing clan.";
            }
        }

        return $res;
    }

    /**
     * @param $fields
     * @param $facebookId
     */
    public function updateAccountDetails($fields, $facebookId)
    {
        $fields["facebookid"] = $facebookId;

        $fields["teetxt"] = $GLOBALS['purifier']->purify($fields["teetxt"]);
        $fields["clantxt"] = $GLOBALS['purifier']->purify($fields["clantxt"]);

        if ($fields["tee"] != "") {
            $fields["tee"] = $this->statRepository->getTeeName($fields["tee"]);
        }
        if ($fields["clan"] != "") {
            $fields["clan"] = $this->statRepository->getClanName($fields["clan"]);
        }

        $setStr = "";
        foreach ($fields as $field => $val) {
            if ($field != "facebookid") {
                $setStr = $setStr . ",$field=:$field";
            }
        }
        $setStr = substr($setStr, 1);

        $this->databaseConnection->sqlInsert($fields, "accounts", $setStr);
    }
}