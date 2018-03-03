<?php
/*
 * This file is mostly copied from the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that is distributed with the TYPO3 CMS Code.
 */

namespace TwStats\Core\Utility;


use TwStats\Core\General\SingletonInterface;

class GeneralUtility
{
    /**
     * Singleton instances returned by makeInstance, using the class names as
     * array keys
     *
     * @var array<\TwStats\Core\Backend\SingletonInterface>
     */
    protected static $singletonInstances = [];

    /**
     * Instances returned by makeInstance, using the class names as array keys
     *
     * @var array<array><object>
     */
    protected static $nonSingletonInstances = [];

    /**
     * Returns the 'GLOBAL' value of incoming data from POST or GET, with priority to POST (that is equivalent to 'GP' order)
     * To enhance security in your scripts, please consider using GeneralUtility::_GET or GeneralUtility::_POST if you already
     * know by which method your data is arriving to the scripts!
     *
     * @param string $var GET/POST var to return
     * @return mixed POST var named $var and if not set, the GET var of the same name.
     */
    public static function _GP($var)
    {
        if (empty($var)) {
            return '';
        }
        if (isset($_POST[$var])) {
            $value = $_POST[$var];
        } elseif (isset($_GET[$var])) {
            $value = $_GET[$var];
        } else {
            $value = null;
        }
        // This is there for backwards-compatibility, in order to avoid NULL
        if (isset($value) && !is_array($value)) {
            $value = (string)$value;
        }
        return $value;
    }

    /**
     * You can also pass arguments for a constructor:
     * \TwStats\Core\Backend\Utility\GeneralUtility::makeInstance(\myClass::class, $arg1, $arg2, ..., $argN)
     *
     * @param string $className name of the class to instantiate, must not be empty and not start with a backslash
     * @return object the created instance
     * @throws \InvalidArgumentException if $className is empty or starts with a backslash
     */
    public static function makeInstance($className)
    {
        if (!is_string($className) || empty($className)) {
            throw new \InvalidArgumentException('$className must be a non empty string.');
        }
        // Never instantiate with a beginning backslash, otherwise things like singletons won't work.
        if ($className[0] === '\\') {
            throw new \InvalidArgumentException(
                '$className "' . $className . '" must not start with a backslash.'
            );
        }
        if (!class_exists($className)) {
            throw new \InvalidArgumentException(
                '$className "' . $className . '" does not exist.'
            );
        }

        // Return singleton instance if it is already registered
        if (isset(self::$singletonInstances[$className])) {
            return self::$singletonInstances[$className];
        }
        // Return instance if it has been injected by addInstance()
        if (
            isset(self::$nonSingletonInstances[$className])
            && !empty(self::$nonSingletonInstances[$className])
        ) {
            return array_shift(self::$nonSingletonInstances[$className]);
        }
        // Create new instance and call constructor with parameters
        $instance = static::instantiateClass($className, func_get_args());
        // Register new singleton instance
        if ($instance instanceof SingletonInterface) {
            self::$singletonInstances[$className] = $instance;
        }
        return $instance;
    }

    /**
     * Speed optimized alternative to ReflectionClass::newInstanceArgs()
     *
     * @param string $className Name of the class to instantiate
     * @param array $arguments Arguments passed to self::makeInstance() thus the first one with index 0 holds the requested class name
     * @return mixed
     */
    protected static function instantiateClass($className, $arguments)
    {
        switch (count($arguments)) {
            case 1:
                $instance = new $className();
                break;
            case 2:
                $instance = new $className($arguments[1]);
                break;
            case 3:
                $instance = new $className($arguments[1], $arguments[2]);
                break;
            case 4:
                $instance = new $className($arguments[1], $arguments[2], $arguments[3]);
                break;
            case 5:
                $instance = new $className($arguments[1], $arguments[2], $arguments[3], $arguments[4]);
                break;
            case 6:
                $instance = new $className($arguments[1], $arguments[2], $arguments[3], $arguments[4], $arguments[5]);
                break;
            case 7:
                $instance = new $className($arguments[1], $arguments[2], $arguments[3], $arguments[4], $arguments[5], $arguments[6]);
                break;
            case 8:
                $instance = new $className($arguments[1], $arguments[2], $arguments[3], $arguments[4], $arguments[5], $arguments[6], $arguments[7]);
                break;
            case 9:
                $instance = new $className($arguments[1], $arguments[2], $arguments[3], $arguments[4], $arguments[5], $arguments[6], $arguments[7], $arguments[8]);
                break;
            default:
                // The default case for classes with constructors that have more than 8 arguments.
                // This will fail when one of the arguments shall be passed by reference.
                // In case we really need to support this edge case, we can implement the solution from here: https://review.typo3.org/26344
                try {
                    $class = new \ReflectionClass($className);
                } catch (\ReflectionException $e) {
                    return null;
                }
                array_shift($arguments);
                $instance = $class->newInstanceArgs($arguments);
        }
        return $instance;
    }

    /**
     * @return string
     */
    public static function joinPaths() {
        $paths = [];

        foreach (func_get_args() as $arg) {
            if ($arg !== '') { $paths[] = $arg; }
        }

        return preg_replace('#/+#','/',join('/', $paths));
    }

    /**
     * sets location header to url
     *
     * @param $url
     */
    public static function redirectToUri($url)
    {
        header("Location: $url");
        exit(0);
    }

    /**
     * Redirect with POST data.
     * Not the best solution since our server now acts like a client and saved data like facebook user
     * htaccess etc are not saved
     *
     * @param string $url URL.
     * @param array $data POST data. Example: array('foo' => 'var', 'id' => 123)
     * @param array $curlOptions Curl options. Example: array(CURLOPT_FAILONERROR => 1, CURLOPT_NOBODY => 1)
     */
    public static function redirectPostToUri($url, array $data, $curlOptions = [])
    {
        $ch = curl_init($url);

        // possible to modify curl before requesting
        foreach ($curlOptions as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        // hard coded options which would break the functionality if changed
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // ToDo: extract to settings, verifying ssl should always be true
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $resp = curl_exec($ch);
        if (curl_errno($ch)) {
            echo "Error loading '$url', " . curl_errno($ch) . " (" . curl_error($ch) . ")";
            die;
        } else {
            echo $resp;
            die;
        }
    }
}