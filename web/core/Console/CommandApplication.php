<?php

namespace TwStats\Core\Console;

use Symfony\Component\Console\Input\ArgvInput;
use TwStats\Core\Backend\Database;
use TwStats\Core\Backend\SystemEnvironmentBuilder;
use TwStats\Core\General\ApplicationInterface;
use TwStats\Core\Utility\GeneralUtility;

class CommandApplication implements ApplicationInterface
{
    /**
     * database connection
     *
     * @var Database
     */
    private $database = null;

    /**
     * @var InputResolver
     */
    private $inputResolver = null;

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
         * initialize the file resolver
         */
        $this->inputResolver = GeneralUtility::makeInstance(InputResolver::class);
    }

    /**
     * Starting point
     *
     * @param callable $execute
     * @return void
     */
    public function run(callable $execute = null)
    {
        $this->inputResolver->setInput(new InputHandler());
        $requestedClass = $this->inputResolver->resolveClass();
        $requestedFunction = $this->inputResolver->resolveFunction();
        $requestArguments = $this->inputResolver->resolveArguments();

        if ($execute !== null) {
            call_user_func($execute);
        }

        $classInstance = GeneralUtility::makeInstance($requestedClass);
        if (!method_exists($classInstance, $requestedFunction)) {
            throw new \InvalidArgumentException("The class " . $requestedClass . " has no method named " . $requestedFunction);
        }
        call_user_func_array(array($classInstance, $requestedFunction), $requestArguments);
    }
}
