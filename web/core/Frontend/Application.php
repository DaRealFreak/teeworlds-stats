<?php

namespace TwStats\Core\Frontend;

use TwStats\Core\Backend\Database;
use TwStats\Core\Backend\SystemEnvironmentBuilder;
use TwStats\Core\Utility\GeneralUtility;

class Application implements ApplicationInterface
{

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
        $GLOBALS['DB'] = GeneralUtility::makeInstance(Database::class);
        /*
         * initialize the frontend handler
         */
        $GLOBALS['FE'] = GeneralUtility::makeInstance(Twig::class);
    }

    /**
     * Starting point
     *
     * @param callable $execute
     * @return void
     */
    public function run(callable $execute = null)
    {

    }
}
