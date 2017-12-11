<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (version_compare(PHP_VERSION, '5.4.0', '<')) {
    die('TW-Stats requires PHP 5.4 or above for Facebook SDK');
}

// Set up the application for the Frontend
call_user_func(function () {
    /** @noinspection PhpIncludeInspection */
    $classLoader = require rtrim(realpath(__DIR__), '\\/') . '/../../../../vendor/autoload.php';
    (new \TwStats\Core\Frontend\Application($classLoader))->run();
});
