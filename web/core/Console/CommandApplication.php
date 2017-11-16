<?php

namespace TwStats\Core\Console;

use TwStats\Core\Backend\Database;
use TwStats\Core\Backend\SystemEnvironmentBuilder;
use TwStats\Core\General\ApplicationInterface;
use TwStats\Core\Utility\GeneralUtility;

class CommandApplication implements ApplicationInterface
{
    /**
     * database connection
     *
     * @var Database|null
     */
    private $database = null;

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
    }

    /**
     * Starting point
     *
     * @param callable $execute
     * @return void
     */
    public function run(callable $execute = null)
    {
        if ($execute !== null) {
            call_user_func($execute);
        }

        // ToDo: how to start properly?
        //GeneralUtility::makeInstance($this->requestHandler->getRequestedClass());
    }
}
