<?php

namespace TwStats\Ext\TwigExtension;


use TwStats\Core\General\SingletonInterface;

class SlugifyUrl extends \Twig_Extension implements SingletonInterface
{
    /**
     * default twig function to map filter to function
     *
     * @return array
     */
    public function getFilters()
    {
        return array(
            new \Twig_SimpleFilter('slugify', array($this, 'slugifyUrl')),
        );
    }

    /**
     * ToDo: implement this function
     *
     * @param $currentUrl
     * @return string
     */
    public function slugifyUrl($currentUrl)
    {
        return $currentUrl . " is now pretty";
    }
}