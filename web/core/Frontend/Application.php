<?php

namespace TwStats\Core\Frontend;

class Application implements ApplicationInterface
{

    /**
     * Constructor setting up legacy constant and register available Request Handlers
     *
     * @param \Composer\Autoload\ClassLoader $classLoader an instance of the class loader
     */
    public function __construct($classLoader)
    {

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
