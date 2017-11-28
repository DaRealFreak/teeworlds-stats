<?php

namespace TwStats\Core\General;


use Symfony\Component\Yaml\Yaml;

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
        // FixMe: different entrypoints like CLI will fail relative paths
        $services = Yaml::parse(@file_get_contents('../services.yml'));
        if ($services) {
            $this->settings = $services;
        }
    }

    /**
     * @param $key
     * @return mixed|null
     */
    public function getSetting($key)
    {
        if (array_key_exists($key, $this->settings)) {
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