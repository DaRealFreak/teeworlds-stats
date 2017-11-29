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

        define('TwStats_root', realpath(GeneralUtility::joinPaths($baseDir, "..")));
        define('TwStats_path', realpath(GeneralUtility::joinPaths($baseDir, "twstats")));
        define('TwStats_main_class', "main");
        define('TwStats_main_file', realpath(GeneralUtility::joinPaths(TwStats_path, TwStats_main_class . ".php")));
        define('TwStats_Ext_namespace', "TwStats\\Ext\\");
        define('TwStats_Cron_namespace', "TwStats\\Cron\\");
        define('TwStats_Core_namespace', "TwStats\\Core\\");
        define('TwStats_templates', realpath(GeneralUtility::joinPaths($baseDir, "templates")));
        define('TwStats_template_cache', realpath(GeneralUtility::joinPaths(TwStats_templates, "cache")));
    }
}