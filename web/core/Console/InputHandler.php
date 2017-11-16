<?php

namespace TwStats\Core\Console;


use TwStats\Core\General\SingletonInterface;

class InputHandler implements SingletonInterface
{

    /**
     * @var array
     */
    public $tokens = [];

    /**
     * InputHandler constructor.
     * @param array|null $argv
     */
    public function __construct(array $argv = null)
    {
        if (null === $argv) {
            $argv = $_SERVER['argv'];
        }

        // strip the application name
        array_shift($argv);

        $this->tokens = $argv;
    }

    /**
     * retrieve the first argument
     *
     * @return string|null
     */
    public function getFirstArgument()
    {
        if (isset($this->tokens[0])) {
            return $this->tokens[0];
        } else {
            return null;
        }
    }

    /**
     * get all arguments
     *
     * @return array|null
     */
    public function getArguments()
    {
        return $this->tokens;
    }

}