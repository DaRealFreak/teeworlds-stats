<?php

namespace TwStats\Core\Backend;

abstract class AbstractRepository
{
    /**
     * database connection
     *
     * @var Database
     */
    protected $databaseConnection = null;

    /**
     * AbstractController constructor.
     */
    public function __construct()
    {
        $this->databaseConnection = $GLOBALS['DB'];
        if (method_exists($this, "initialize")) {
            call_user_func_array(array($this, 'initialize'), func_get_args());
        }
    }
}
