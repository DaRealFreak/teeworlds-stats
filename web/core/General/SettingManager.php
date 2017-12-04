<?php

namespace TwStats\Core\General;


use Symfony\Component\Yaml\Yaml;
use TwStats\Core\Utility\GeneralUtility;

class SettingManager implements SingletonInterface
{
    /**
     * @var array
     */
    protected $settings = [];

    /**
     * SettingManager constructor.
     */
    public function __construct()
    {
        $services = Yaml::parse(@file_get_contents(GeneralUtility::joinPaths(TwStats_root, 'services.yml')));
        if ($services) {
            $this->settings = $services;
        }
    }

    /**
     * @param $key
     * @return bool
     */
    public function hasSetting($key) {
        return array_key_exists($key, $this->settings);
    }

    /**
     * @param $key
     * @return mixed|null
     */
    public function getSetting($key)
    {
        if ($this->hasSetting($key)) {
            return $this->settings[$key];
        } else {
            return null;
        }
    }

    /**
     * @param $key
     * @param $value
     */
    public function setSetting($key, $value)
    {
        $this->settings[$key] = $value;
    }
}