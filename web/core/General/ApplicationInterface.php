<?php

namespace TwStats\Core\General;

interface ApplicationInterface
{
    /**
     * Starting point
     *
     * @param callable $execute
     * @return void
     */
    public function run(callable $execute = null);
}