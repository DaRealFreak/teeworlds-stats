<?php

namespace TwStats\Ext;

use TwStats\Core\Frontend\AbstractController;

class Main extends AbstractController
{

    /**
     * run function
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

        // ToDo: implement Facebook SDK again
        $user = 0; //getFacebookID();

        if ($user) {
            $page['logged'] = true;

            $account = getAccountDetails($user);
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

        /*
         * ToDo: change session arguments to actual post arguments
         */
        $options = [
            'suggestionsTee',
            'suggestionsClan',
            'suggestionsServer',
            'missingTee',
            'missingClan',
            'missingServer'
        ];
        foreach ($options as $argument) {
            if ($this->sessionHandler->hasArgument($argument)) {
                $page[$argument] = $this->sessionHandler->getArgument($argument);
                $this->sessionHandler->removeAgument($argument);
            }
        }

        $this->frontendHandler->renderTemplate("main.twig", $page);
    }
}