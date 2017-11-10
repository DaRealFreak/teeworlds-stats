<?php

namespace TwStats\Core\Frontend;

use TwStats\Core\Backend\Database;
use TwStats\Core\Backend\RequestHandler;
use TwStats\Core\Utility\GeneralUtility;

abstract class AbstractController
{
    /**
     * database connection
     *
     * @var Database|null
     */
    private $database = null;

    /**
     * frontend handler
     *
     * @var Twig|null
     */
    private $frontendHandler = null;

    /**
     * @var RequestHandler|null
     */
    private $requestHandler = null;

    /**
     * AbstractController constructor.
     */
    public function __construct()
    {
        $this->database = $GLOBALS['DB'];
        $this->frontendHandler = $GLOBALS['FE'];
        $this->requestHandler = GeneralUtility::makeInstance(RequestHandler::class);
        $this->run();
    }

    /**
     * Starting point
     *
     * @return void
     */
    abstract public function run();
}
