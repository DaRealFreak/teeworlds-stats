<?php

namespace TwStats\Ext\TwigExtension;


use TwStats\Core\Backend\RequestHandler;
use TwStats\Core\General\SingletonInterface;
use TwStats\Core\Utility\PrettyUrl;

class SlugifyUrl extends \Twig_Extension implements SingletonInterface
{
    /**
     * @return array
     */
    public function getFunctions()
    {
        return array(
            new \Twig_Function('slugify', array($this, 'slugifyUrl')),
        );
    }

    /**
     * slugify window title
     * needed for example on form submits since we can't slugify
     * the urls in the backend in this case
     */
    public function slugifyUrl()
    {
        $class = ltrim(strtok(RequestHandler::getUrl(), "?"), '/');
        $url = PrettyUrl::buildPrettyUri($class, $_GET);
        $title = "Title";
        echo '<script>window.history.pushState("' . $url . '", "' . $title . '", "' . $url . '");</script>';
    }
}