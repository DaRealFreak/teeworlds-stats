<?php

namespace TwStats\Ext;

use TwStats\Core\Frontend\AbstractController;

class Main extends AbstractController
{

    /**
     * ToDo: extract to url handler
     *
     * @param string $page
     * @param array $params
     * @param bool $usePath
     * @return string
     */
    private function myurl($page = "", $params = array(), $usePath = true)
    {
        if (!$usePath) {
            $params['p'] = $page;
            $url = "index.php?" . http_build_query($params);
        } else {
            $url = $page;
            if (!empty($params))
                $url = $url . "?" . http_build_query($params);
        }

        return $url;
    }

    public function run()
    {
        $items = array(
            array('text' => 'Game statistics',
                'url' => $this->myurl("general"),
                'class' => 'icon-globe'),
            array('text' => 'Search',
                'url' => $this->myurl(""),
                'class' => 'icon-search')
        );

        // ToDo: implement Facebook SDK again
        $user = 0; //getFacebookID();

        if ($user) {
            $page['logged'] = true;

            $account = getAccountDetails($user);
            if (!empty($account["tee"]))
                $items[] = array('text' => $account['tee'],
                    'url' => $this->myurl("tee", array("n" => $account['tee'])),
                    'class' => 'icon-user');
            if (!empty($account["clan"]))
                $items[] = array('text' => $account['clan'],
                    'url' => $this->myurl("clan", array("n" => $account['clan'])),
                    'class' => 'icon-home');

            $items[] = array('text' => 'Account', 'url' => $this->myurl("account"), 'class' => 'icon-pencil');
        }

        $items[] = array('text' => 'About', 'url' => $this->myurl("about"), 'class' => 'icon-info-sign');

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