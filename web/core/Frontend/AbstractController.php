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
    protected $database = null;

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
     * @var PrettyUrl|null
     */
    protected $prettyUrl = null;

    /**
     * AbstractController constructor.
     */
    public function __construct()
    {
        $this->database = $GLOBALS['DB'];
        $this->frontendHandler = $GLOBALS['FE'];
        $this->requestHandler = GeneralUtility::makeInstance(RequestHandler::class);
        $this->sessionHandler = GeneralUtility::makeInstance(SessionHandler::class);
        $this->prettyUrl = GeneralUtility::makeInstance(PrettyUrl::class);
        $this->run();
    }

    /**
     * Starting point
     *
     * @return void
     */
    abstract public function run();
}
