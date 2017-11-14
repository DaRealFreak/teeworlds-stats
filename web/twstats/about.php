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
        $items = array(
            array('text' => 'Game statistics',
                'url' => $this->prettyUrl->buildPrettyUri("general"),
                'class' => 'icon-globe'),
            array('text' => 'Search',
                'url' => $this->prettyUrl->buildPrettyUri(""),
                'class' => 'icon-search')
        );

        $user = $this->facebook->getFacebookID();
        $page['logged'] = true;
        if ($user) {
            $account = $this->facebook->getAccountDetails($user);
            if (!empty($account["tee"]))
                $items[] = array('text' => $account['tee'],
                    'url' => $this->prettyUrl->buildPrettyUri("tee", array("n" => $account['tee'])),
                    'class' => 'icon-user');
            if (!empty($account["clan"]))
                $items[] = array('text' => $account['clan'],
                    'url' => $this->prettyUrl->buildPrettyUri("clan", array("n" => $account['clan'])),
                    'class' => 'icon-home');

            $items[] = array('text' => 'Account', 'url' => $this->prettyUrl->buildPrettyUri("account"), 'class' => 'icon-pencil');
        }

        $items[] = array('text' => 'About', 'url' => $this->prettyUrl->buildPrettyUri("about"), 'class' => 'icon-info-sign');

        $page['navigation'] = $this->frontendHandler->getTemplateHtml("views/navigation.twig", array("items" => $items));

        $this->frontendHandler->renderTemplate("about.twig", $page);
    }
}


