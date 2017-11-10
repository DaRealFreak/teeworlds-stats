<?php

namespace TwStats\Core\Frontend;

use TwStats\Core\Backend\Database;
use TwStats\Core\Backend\RequestHandler;
use TwStats\Core\Backend\SystemEnvironmentBuilder;
use TwStats\Core\Utility\GeneralUtility;

class Application implements ApplicationInterface
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
     * Constructor setting up legacy constant and register available Request Handlers
     *
     * @param \Composer\Autoload\ClassLoader $classLoader an instance of the class loader
     */
    public function __construct($classLoader)
    {
        /*
         * run the environmental builder
         */
        SystemEnvironmentBuilder::run();
        /*
         * initialize the database directly
         */
        $GLOBALS['DB'] = $this->database = GeneralUtility::makeInstance(Database::class);
        /*
         * initialize the frontend handler
         */
        $GLOBALS['FE'] = $this->frontendHandler = GeneralUtility::makeInstance(Twig::class);
        /*
         * initialize the request handler
         */
        $this->requestHandler = GeneralUtility::makeInstance(RequestHandler::class);
    }

    /**
     * Starting point
     *
     * @param callable $execute
     * @return void
     */
    public function run(callable $execute = null)
    {
        $requestedFile = $this->requestHandler->getRequestedPath();
    }
}
