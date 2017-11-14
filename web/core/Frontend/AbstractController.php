<?php

namespace TwStats\Core\Frontend;

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
     * frontend handler
     *
     * @var Twig
     */
    protected $frontendHandler = null;

    /**
     * @var RequestHandler
     */
    protected $requestHandler = null;

    /**
     * @var SessionHandler
     */
    protected $sessionHandler = null;

    /**
     * @var PrettyUrl
     */
    protected $prettyUrl = null;

    /**
     * AbstractController constructor.
     */
    public function __construct()
    {
        $this->databaseConnection = $GLOBALS['DB'];
        $this->frontendHandler = $GLOBALS['FE'];
        $this->requestHandler = GeneralUtility::makeInstance(RequestHandler::class);
        $this->sessionHandler = GeneralUtility::makeInstance(SessionHandler::class);
        $this->prettyUrl = GeneralUtility::makeInstance(PrettyUrl::class);
        if (method_exists($this, "initialize")) {
            call_user_func_array(array($this,'initialize'), func_get_args());
        }
        $this->run();
    }

    /**
     * Starting point
     *
     * @return void
     */
    abstract public function run();
}
