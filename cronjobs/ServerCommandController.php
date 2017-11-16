<?php

namespace TwStats\Cron;


use TwStats\Core\Console\AbstractController;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Ext\TwRequest\TwRequest;

class ServerCommandController extends AbstractController
{
    /**
     * gather servers from the master servers
     */
    public function gatherServers()
    {
        ini_set("max_execution_time", 10 * 60);

        $twdata = GeneralUtility::makeInstance(TwRequest::class);
        $twdata->loadServersFromMasterservers();
        $servers = $twdata->getServers();

        // ToDo: don't truncate servers, add timestamp and check last time active
        $this->databaseConnection->sqlQuery("TRUNCATE `servers`");

        $req = $this->databaseConnection->sqlPrepare("INSERT INTO `servers` (`address`, `port`, `version`) VALUES (?, ?, ?)");

        $count = 0;
        foreach ($servers as $server) {
            $req->execute(array($server[0], $server[1], $server[2]));
            ++$count;
        }

        echo sprintf("Fetched %d servers from the master servers\n", $count);
    }
}
