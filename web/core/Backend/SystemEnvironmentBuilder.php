<?php

namespace TwStats\Core\Backend;


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

        define('TwStats_path', GeneralUtility::joinPaths($baseDir, "twstats"));
        define('TwStats_main', GeneralUtility::joinPaths(TwStats_path, "main.php"));
        define('TwStats_namespace', "\\TwStats\\Ext\\");
        define('TwStats_templates', GeneralUtility::joinPaths($baseDir, "templates"));
        define('TwStats_template_cache', GeneralUtility::joinPaths(TwStats_templates, "cache"));
    }
}