<?php

namespace TwStats\Core\Frontend;


use TwStats\Core\General\SettingManager;
use TwStats\Core\General\SingletonInterface;
use TwStats\Core\Utility\GeneralUtility;

class Twig implements SingletonInterface
{

    /**
     * setting manager
     *
     * @var SettingManager|null
     */
    private $settingManager = null;

    /**
     * dependencies
     *
     * @var array
     */
    private $dependencies = array();

    /**
     * twig instance
     *
     * @var null|\Twig_Environment
     */
    private $twig = null;

    /**
     * Twig constructor.
     */
    public function __construct()
    {
        $this->settingManager = GeneralUtility::makeInstance(SettingManager::class);
        $this->dependencies = $this->includeCharismaLibs();

        $loader = new \Twig_Loader_Filesystem(TwStats_templates);
        $this->twig = new \Twig_Environment($loader, array(
            'cache' => TwStats_template_cache,
        ));
        $this->loadExtensions();
    }

    /**
     * render a template and pass the parameters
     *
     * @param string $templateFile
     * @param array $params
     * @param bool $cache
     */
    public function renderTemplate($templateFile, $params = [], $cache = False)
    {
        echo $this->getTemplateHtml($templateFile, $params, $cache);
    }

    /**
     * possibility to add extensions to twig
     *
     * @param string|object $extension
     */
    public function addExtension($extension)
    {
        if (is_string($extension)) {
            $extension = GeneralUtility::makeInstance($extension);
        } elseif (!is_object($extension)) {
            throw new \InvalidArgumentException("Twig Extension can be registered with either an instance or namespace string");
        }
        if (!$extension instanceof \Twig_ExtensionInterface) {
            throw new \InvalidArgumentException("Twig Extension has to implement \Twig_ExtensionInterface");
        }
        $this->twig->addExtension($extension);
    }

    /**
     * render a template and pass the parameters
     *
     * @param string $templateFile
     * @param array $params
     * @param bool $cache
     * @return string
     */
    public function getTemplateHtml($templateFile, $params = [], $cache = False)
    {
        if ($cache && !$this->twig->getCache()) {
            $this->twig->setCache(TwStats_template_cache);
        } else {
            $this->twig->setCache(False);
        }
        $template = $this->twig->load($templateFile);
        $params += $this->dependencies;
        return $template->render($params);
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

    /**
     * load all extensions from settings in services.yml
     */
    private function loadExtensions()
    {
        $extensions = $this->settingManager->getSetting("twig-extensions");
        if ($extensions) {
            foreach ($extensions as $extension) {
                $this->addExtension($extension);
            }
        }
    }
}