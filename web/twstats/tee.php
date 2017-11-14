<?php

namespace TwStats\Ext;


use TwStats\Core\Frontend\AbstractController;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Ext\Database\AccountRepository;
use TwStats\Ext\Database\StatRepository;
use TwStats\Ext\Facebook\Facebook;
use TwStats\Ext\Youtube\Youtube;

class Tee extends AbstractController
{

    /**
     * @var Facebook
     */
    protected $facebook = null;

    /**
     * @var AccountRepository
     */
    protected $accountRepository = null;

    /**
     * @var StatRepository
     */
    protected $statRepository = null;

    /**
     * initializing function to replace the constructor function
     */
    public function initialize()
    {
        $this->facebook = GeneralUtility::makeInstance(Facebook::class);
        $this->accountRepository = GeneralUtility::makeInstance(AccountRepository::class);
        $this->statRepository = GeneralUtility::makeInstance(StatRepository::class);
    }

    /**
     * Starting point
     *
     * @return void
     */
    public function run()
    {
        $tee = 'Nibiru';

        if (empty($tee)) {
            GeneralUtility::redirectToUri(".");
        }

        $player = $this->statRepository->getPlayer($tee);

        if (!$player) {
            $_SESSION['suggestionsTee'] = $this->statRepository->getSimilarData("tee", $tee);
            $_SESSION['missingTee'] = true;
            GeneralUtility::redirectToUri(".");
        }

        $tee = $player['tee'];

        $teeDetails = $this->accountRepository->getTeeDetails($tee);
        if (!empty($teeDetails["clan"])) {
            $player['clan'] = $teeDetails["clan"];
        }

        $page['title'] = "Teeworlds statistics - $tee";

        $page['tee'] = $tee;
        if (!empty($player['clan'])) {
            $page['clan'] = $player['clan'];
        }
        if (!empty($player['country'])) {
            $page['country'] = $player['country'];
        }

        $items = array(
            array('text' => 'Game statistics',
                'url' => $this->prettyUrl->buildPrettyUri("general"),
                'class' => 'icon-globe'),
            array('text' => 'Search',
                'url' => $this->prettyUrl->buildPrettyUri(""),
                'class' => 'icon-search'),
        );

        $user = $this->facebook->getFacebookID();
        if ($user) {
            $page['logged'] = true;

            $account = $this->facebook->getAccountDetails($user);
            if (!empty($account["tee"])) {
                $items[] = array('text' => $account['tee'],
                    'url' => $this->prettyUrl->buildPrettyUri("tee", array("n" => $account['tee'])),
                    'class' => 'icon-user');
            }
            if (!empty($account["clan"])) {
                $items[] = array('text' => $account['clan'],
                    'url' => $this->prettyUrl->buildPrettyUri("clan", array("n" => $account['clan'])),
                    'class' => 'icon-home');
            }
        }

        if (!empty($player['clan'])) {
            $items[] = array('text' => $player['clan'],
                'url' => $this->prettyUrl->buildPrettyUri("clan", array("n" => $player['clan'])),
                'class' => 'icon-home');
        }

        if ($user) {
            $items[] = array('text' => 'Account', 'url' => $this->prettyUrl->buildPrettyUri("account"), 'class' => 'icon-pencil');
        }

        $items[] = array('text' => 'About',
            'url' => $this->prettyUrl->buildPrettyUri("about"),
            'class' => 'icon-info-sign');

        $page['navigation'] = $this->frontendHandler->getTemplateHtml("views/navigation.twig", array("items" => $items));

        if (!empty($teeDetails["teetxt"])) {
            if (strip_tags($teeDetails["teetxt"]) != "") {
                $page["teetxt"] = Youtube::integrateYoutubeVideos($teeDetails["teetxt"]);
            }
        }

        if ($teeDetails["teemods"] == 1) {
            $hist_mods = $this->statRepository->gethisto("tee", $tee, "mod");
            $page['mods'] = $this->frontendHandler->getTemplateHtml("views/pie.twig",
                array("id" => "piemods",
                    "name" => "$tee's favorite mods",
                    "histogram" => $hist_mods));
        }

        if ($teeDetails["teemaps"] == 1) {
            $hist_maps = $this->statRepository->gethisto("tee", $tee, "map");
            $page['maps'] = $this->frontendHandler->getTemplateHtml("views/pie.twig",
                array("id" => "piemaps",
                    "name" => "$tee's favorite maps",
                    "histogram" => $hist_maps));
        }

        if ($teeDetails["teehours"] == 1) {
            $histhours = $this->statRepository->gethours("tee", $tee);
            $page['hours'] = $this->frontendHandler->getTemplateHtml("views/bars.twig",
                array("id" => "hourbars",
                    "name" => "$tee's online time per hour",
                    "histogram" => $histhours));
        }

        if ($teeDetails["teedays"] == 1) {
            $histdays = $this->statRepository->getdays("tee", $tee);
            $page['days'] = $this->frontendHandler->getTemplateHtml("views/bars.twig",
                array("id" => "daybars",
                    "name" => "$tee's online time per day (Monday to Sunday)",
                    "histogram" => $histdays));
        }

        $this->frontendHandler->renderTemplate("templates/tee.twig", $page);
    }

    private function old_run()
    {
        $tee = empty($uri[1]) ? '' : $uri[1];
        $tee = empty($gp['n']) ? $tee : $gp['n'];

        if (empty($tee))
            redirect(".");

        $player = getPlayer($tee);

        if (!$player) {
            $_SESSION['suggestionsTee'] = getSimilarData("tee", $tee);
            $_SESSION['missingTee'] = true;
            redirect(".");
        }

        $tee = $player['tee'];

        $teeDetails = getTeeDetails($tee);
        if (!empty($teeDetails["clan"]))
            $player['clan'] = $teeDetails["clan"];

        $page['title'] = "Teeworlds statistics - $tee";

        $page['tee'] = $tee;
        if (!empty($player['clan']))
            $page['clan'] = $player['clan'];
        if (!empty($player['country']))
            $page['country'] = $player['country'];

        $items = array(
            array('text' => 'Game statistics',
                'url' => myurl("general"),
                'class' => 'icon-globe'),
            array('text' => 'Search',
                'url' => myurl(""),
                'class' => 'icon-search'),
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

        }

        if (!empty($player['clan']))
            $items[] = array('text' => $player['clan'],
                'url' => myurl("clan", array("n" => $player['clan'])),
                'class' => 'icon-home');

        if ($user)
            $items[] = array('text' => 'Account', 'url' => myurl("account"), 'class' => 'icon-pencil');


        $items[] = array('text' => 'About',
            'url' => myurl("about"),
            'class' => 'icon-info-sign');

        $page['navigation'] = $twig->render("views/navigation.twig", array("items" => $items));

        if (!empty($teeDetails["teetxt"]))
            if (strip_tags($teeDetails["teetxt"]) != "")
                $page["teetxt"] = integrateYoutubeVideos($teeDetails["teetxt"]);

        if ($teeDetails["teemods"] == 1) {
            $hist_mods = gethisto("tee", $tee, "mod");
            $page['mods'] = $twig->render("views/pie.twig",
                array("id" => "piemods",
                    "name" => "$tee's favorite mods",
                    "histogram" => $hist_mods));
        }

        if ($teeDetails["teemaps"] == 1) {
            $hist_maps = gethisto("tee", $tee, "map");
            $page['maps'] = $twig->render("views/pie.twig",
                array("id" => "piemaps",
                    "name" => "$tee's favorite maps",
                    "histogram" => $hist_maps));
        }

        if ($teeDetails["teehours"] == 1) {
            $histhours = gethours("tee", $tee);
            $page['hours'] = $twig->render("views/bars.twig",
                array("id" => "hourbars",
                    "name" => "$tee's online time per hour",
                    "histogram" => $histhours));
        }

        if ($teeDetails["teedays"] == 1) {
            $histdays = getdays("tee", $tee);
            $page['days'] = $twig->render("views/bars.twig",
                array("id" => "daybars",
                    "name" => "$tee's online time per day (Monday to Sunday)",
                    "histogram" => $histdays));
        }


        echo trender("templates/tee.twig", $page);

    }
}
