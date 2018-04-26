<?php

namespace TwStats\Ext;


use TwStats\Core\Frontend\AbstractController;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Ext\Facebook\Facebook;

class About extends AbstractController
{

    /**
     * @var Facebook|null
     */
    protected $facebook = null;

    /**
     * initializing function to replace the constructor function
     */
    public function initialize()
    {
        $this->facebook = GeneralUtility::makeInstance(Facebook::class);
    }

    /**
     * Starting point
     *
     * @return void
     */
    public function run()
    {
        $this->frontendHandler->renderTemplate("about.twig");
    }
}


