<?php

namespace TwStats\Core\Frontend;


use TwStats\Core\General\SettingManager;
use TwStats\Core\General\SingletonInterface;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Core\Utility\StringUtility;
use WyriHaximus\HtmlCompress\Factory;

class Twig implements SingletonInterface
{

    /**
     * setting manager
     *
     * @var SettingManager|null
     */
    private $settingManager = null;

    /**
     * html compressor
     *
     * @var null|\WyriHaximus\HtmlCompress\Parser
     */
    private $htmlCompressor = null;

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
        $this->htmlCompressor = Factory::construct();

        $this->dependencies = $this->includeDependencies();

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
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    public function renderTemplate($templateFile, $params = [], $cache = True)
    {
        $templateHtml = $this->getTemplateHtml($templateFile, $params, $cache);
        if ($this->settingManager->getSetting("compress-html")) {
            $templateHtml = $this->htmlCompressor->compress($templateHtml);
        }
        echo $templateHtml;
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
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    public function getTemplateHtml($templateFile, $params = [], $cache = true)
    {
        if ($this->settingManager->hasSetting("cache")) {
            $cache = $this->settingManager->getSetting("cache");
        }

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
    private function includeDependencies()
    {
        $baseDir = dirname($_SERVER['PHP_SELF']);

        if (StringUtility::startsWith($baseDir, "/")) {
            $baseDir = ltrim($baseDir, '/');
        }

        if (strlen($baseDir) > 1) {
            $baseDir = $baseDir . "/";
        }

        $res = ["basedir" => $baseDir];

        // ToDo: extract these out of the code
        $res['css'] = ['/assets/twstats/public/css/bundle/bundle.min.css'];
        $res['js'] = ['/assets/twstats/public/js/bundle/bundle.js'];
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