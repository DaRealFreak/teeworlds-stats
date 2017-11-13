<?php

namespace TwStats\Core\Backend;


use Dotenv\Dotenv;
use TwStats\Core\Utility\GeneralUtility;

class SystemEnvironmentBuilder
{
    /**
     * Run base setup.
     *
     * @param string $relativePathPart Relative path of the entry script back to document root
     * @return void
     */
    public static function run($relativePathPart = '')
    {
        self::definePaths($relativePathPart);
        self::loadDotEnv();
    }

    /**
     * Calculate all required base paths and set as constants.
     *
     * @param string $relativePathPart Relative path of the entry script back to document root
     * @return void
     */
    public static function definePaths($relativePathPart)
    {
        if (isset($relativePathPart)) {
            $baseDir = $relativePathPart;
        } else {
            $baseDir = dirname($_SERVER['PHP_SELF']);
        }

        define('TwStats_root', GeneralUtility::joinPaths($baseDir, ".."));
        define('TwStats_path', GeneralUtility::joinPaths($baseDir, "twstats"));
        define('TwStats_main_class', "main");
        define('TwStats_main_file', GeneralUtility::joinPaths(TwStats_path, TwStats_main_class . ".php"));
        define('TwStats_namespace', "TwStats\\Ext\\");
        define('TwStats_templates', GeneralUtility::joinPaths($baseDir, "templates"));
        define('TwStats_template_cache', GeneralUtility::joinPaths(TwStats_templates, "cache"));
    }

    /**
     * load environmental variables from the .env file
     *
     * @param string $specificPath
     */
    private static function loadDotEnv($specificPath = "")
    {
        if (!$specificPath || !@is_file($specificPath) || !@is_file($specificPath)) {
            $specificPath = TwStats_root;
        }
        $dotenv = new Dotenv($specificPath);
        $dotenv->load();
    }
}