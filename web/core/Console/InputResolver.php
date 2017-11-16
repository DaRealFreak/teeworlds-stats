<?php

namespace TwStats\Core\Console;


use TwStats\Core\General\SingletonInterface;

class InputResolver implements SingletonInterface
{
    /**
     * @var InputHandler|null
     */
    private $input = null;

    /**
     * get the class name from the input handler
     *
     * @return string
     * @throws \RuntimeException
     */
    public function resolveClass()
    {
        if ($this->input === null) {
            throw new \RuntimeException("No input is defined");
        }

        $parsedPath = pathinfo($this->input->getFirstArgument());
        return TwStats_Cron_namespace . ucfirst($parsedPath['filename']);
    }

    /**
     * get the function name from the input handler
     *
     * @return mixed
     * @throws \ArgumentCountError
     * @throws \RuntimeException
     */
    public function resolveFunction()
    {
        if ($this->input === null) {
            throw new \RuntimeException("No input is defined");
        }

        $tokens = $this->input->getArguments();
        if (sizeof($tokens) < 2) {
            throw new \ArgumentCountError("Not enough arguments. Usage: cron_dispatcher.phpsh [class] [function] [arguments]");
        }
        return $tokens[1];
    }

    /**
     * get the arguments from the input handler
     *
     * @return array
     * @throws \RuntimeException
     */
    public function resolveArguments()
    {
        if ($this->input === null) {
            throw new \RuntimeException("No input is defined");
        }

        $tokens = $this->input->getArguments();
        if (sizeof($tokens) < 3) {
            return [];
        }
        return array_slice($tokens, 2);
    }

    /**
     * set the input handler
     *
     * @param InputHandler $inputHandler
     */
    public function setInput($inputHandler)
    {
        $this->input = $inputHandler;
    }
}