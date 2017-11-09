<?php

namespace TwStats\Core\Frontend;

use TwStats\Core\Backend\Database;
use TwStats\Core\Backend\Utility\GeneralUtility;

class Application implements ApplicationInterface
{

    /**
     * Constructor setting up legacy constant and register available Request Handlers
     *
     * @param \Composer\Autoload\ClassLoader $classLoader an instance of the class loader
     */
    public function __construct($classLoader)
    {
        /**
         * initialize the database directly
         */
        $GLOBALS['DB'] = GeneralUtility::makeInstance(Database::class);
        /**
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
