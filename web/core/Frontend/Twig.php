<?php

namespace TwStats\Core\Frontend;


use TwStats\Core\Backend\SingletonInterface;
use TwStats\Core\Backend\Utility\GeneralUtility;

class Twig implements SingletonInterface
{

    /**
     * @var array
     */
    private $dependencies = array();

    /**
     * @var null|\Twig_Environment
     */
    private $twig = null;

    /**
     * Twig constructor.
     *
     * ToDo:
     *  - extract/define the paths globally
     *  - give option to disable/enable the cache
     */
    public function __construct()
    {
        $this->dependencies = $this->includeCharismaLibs();
        $baseDir = dirname($_SERVER['PHP_SELF']);
        $templateDir = GeneralUtility::joinPaths($baseDir, "templates");
        $templateCacheDir = GeneralUtility::joinPaths($templateDir, "cache");
        $loader = new \Twig_Loader_Filesystem($templateDir);
        $this->twig = new \Twig_Environment($loader, array(
            //'cache' => $templateCacheDir,
        ));
    }

    /**
     * render a template and pass the parameters
     *
     * @param string $templateFile
     * @param array $params
     */
    public function renderTemplate($templateFile, $params = [])
    {
        $template = $this->twig->load($templateFile);
        $params += $this->dependencies;
        echo $template->render($params);
    }

    /**
     * include basedir into parameters
     *
     * @return array
     */
    private function includeCharismaLibs()
    {
        $baseDir = dirname($_SERVER['PHP_SELF']);
        if (strlen($baseDir) > 1) {
            $baseDir = $baseDir . "/";
        }

        $res = ["basedir" => $baseDir];
        $dir = GeneralUtility::joinPaths($baseDir, "clibs");
        $res += $this->resolveCharismaLibs($dir);
        return $res;
    }

    /**
     * create a file list of the dependencies recursively
     *
     * ToDo:
     *  - update charisma(current version is old) or remove it?
     *
     * @param $dir
     * @return array
     */
    private function resolveCharismaLibs($dir)
    {
        $res = array("js" => array(), "css" => array());

        if (file_exists($dir . "/dependencies.conf")) {
            foreach (explode("\n", file_get_contents($dir . "/dependencies.conf")) as $fd) {
                $fd = trim($fd);
                if (file_exists("$dir/$fd")) {
                    if (is_dir("$dir/$fd") && !empty($fd)) {
                        $subres = $this->resolveCharismaLibs("$dir/$fd");
                        $res['css'] = array_merge($res['css'], $subres['css']);
                        $res['js'] = array_merge($res['js'], $subres['js']);
                    } elseif (is_file("$dir/$fd")) {
                        if (preg_match("/.*\.css/", $fd)) {
                            $res['css'][] = "$dir/$fd";
                        } elseif (preg_match("/.*\.js/", $fd)) {
                            $res['js'][] = "$dir/$fd";
                        }
                    }
                }
            }
        } else {
            $res['js'] = array_merge($res['js'], glob("$dir/*.js"));
            $res['css'] = array_merge($res['css'], glob("$dir/*.css"));
            foreach (glob("$dir/*", GLOB_ONLYDIR) as $d) {
                $subres = $this->resolveCharismaLibs($d);
                $res['css'] = array_merge($res['css'], $subres['css']);
                $res['js'] = array_merge($res['js'], $subres['js']);
            }
        }
        return $res;
    }
}