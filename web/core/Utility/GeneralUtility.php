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

namespace TwStats\Core\Backend\Utility;


use TwStats\Core\Backend\SingletonInterface;

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
                $class = new \ReflectionClass($className);
                array_shift($arguments);
                $instance = $class->newInstanceArgs($arguments);
        }
        return $instance;
    }

    /**
     * @return string
     */
    public static function joinPaths()
    {
        $args = func_get_args();
        $paths = array();
        foreach ($args as $arg) {
            $paths = array_merge($paths, (array)$arg);
        }

        $paths = array_map(create_function('$p', 'return trim($p, "' . DIRECTORY_SEPARATOR . '");'), $paths);
        $paths = array_filter($paths);
        return join(DIRECTORY_SEPARATOR, $paths);
    }
}