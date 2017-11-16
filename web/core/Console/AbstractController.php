<?php

namespace TwStats\Core\Console;

use TwStats\Core\Backend\Database;
use TwStats\Core\Backend\RequestHandler;
use TwStats\Core\Backend\SessionHandler;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Core\Utility\PrettyUrl;

abstract class AbstractController
{
    /**
     * database connection
     *
     * @var Database
     */
    protected $databaseConnection = null;

    /**
     * @var PrettyUrl
     */
    protected $prettyUrl = null;

    /**
     * AbstractController constructor.
     */
    public final function __construct()
    {
        $this->databaseConnection = $GLOBALS['DB'];
        $this->prettyUrl = GeneralUtility::makeInstance(PrettyUrl::class);
        if (method_exists($this, "initialize")) {
            call_user_func_array(array($this,'initialize'), func_get_args());
        }
    }
}
