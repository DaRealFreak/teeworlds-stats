<?php

namespace TwStats\Core\Frontend;

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