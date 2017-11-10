<?php

namespace TwStats\Ext;

use TwStats\Core\Frontend\AbstractController;

class Main extends AbstractController
{
    public function run()
    {
        echo "run function";
    }

    public function originalFunction($twig) {
        $items = array(
            array('text' => 'Game statistics',
                'url' => myurl("general"),
                'class' => 'icon-globe'),
            array('text' => 'Search',
                'url' => myurl(""),
                'class' => 'icon-search')
        );

        $user = getFacebookID();

        if ($user) {
            $page['logged'] = true;

            $account = getAccountDetails($user);
            if (!empty($account["tee"]))
                $items[] = array('text' => $account['tee'],
                    'url' => myurl("tee", array("n" => $account['tee'])),
                    'class' => 'icon-user');
            if (!empty($account["clan"]))
                $items[] = array('text' => $account['clan'],
                    'url' => myurl("clan", array("n" => $account['clan'])),
                    'class' => 'icon-home');

            $items[] = array('text' => 'Account', 'url' => myurl("account"), 'class' => 'icon-pencil');
        }

        $items[] = array('text' => 'About', 'url' => myurl("about"), 'class' => 'icon-info-sign');

        $page['navigation'] = $twig->render("views/navigation.twig", array("items" => $items));

        if (!empty($_SESSION['suggestionsTee']))
            $page["suggestionsTee"] = $_SESSION['suggestionsTee'];
        if (!empty($_SESSION['suggestionsClan']))
            $page["suggestionsClan"] = $_SESSION['suggestionsClan'];
        if (!empty($_SESSION['suggestionsServer']))
            $page["suggestionsServer"] = $_SESSION['suggestionsServer'];
        if (!empty($_SESSION['missingTee']))
            $page["missingTee"] = $_SESSION['missingTee'];
        if (!empty($_SESSION['missingClan']))
            $page["missingClan"] = $_SESSION['missingClan'];
        if (!empty($_SESSION['missingServer']))
            $page["missingServer"] = $_SESSION['missingServer'];

        unset($_SESSION['suggestionsClan'],
            $_SESSION['suggestionsServer'],
            $_SESSION['suggestionsTee'],
            $_SESSION['missingTee'],
            $_SESSION['missingClan'],
            $_SESSION['missingServer']);

        echo trender("templates/main.twig", $page);
    }
}