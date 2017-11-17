<?php

namespace TwStats\Ext;


use TwStats\Core\Frontend\AbstractController;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Ext\Database\AccountRepository;
use TwStats\Ext\Database\StatRepository;
use TwStats\Ext\Facebook\Facebook;
use TwStats\Ext\Youtube\Youtube;

class Clan extends AbstractController
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
        $clan = $this->requestHandler->getArgument('n');

        if (empty($clan)) {
            GeneralUtility::redirectToUri($this->requestHandler->getFQDN());
        }

        $name = $this->statRepository->getClanName($clan);
        if (!$name) {
            $payload = [
                'suggestionsClan' => $this->statRepository->getSimilarData("clan", $clan),
                'missingClan' => true
            ];
            GeneralUtility::redirectPostToUri($this->requestHandler->getFQDN(), $payload);
        }
        $clan = $name;

        $items = array(
            array('text' => 'Game statistics',
                'url' => $this->prettyUrl->buildPrettyUri("general"),
                'class' => 'icon-globe'),
            array('text' => 'Search',
                'url' => $this->prettyUrl->buildPrettyUri(""),
                'class' => 'icon-search')
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

            $items[] = array('text' => 'Account', 'url' => $this->prettyUrl->buildPrettyUri("account"), 'class' => 'icon-pencil');
        }

        $items[] = array('text' => 'About', 'url' => $this->prettyUrl->buildPrettyUri("about"), 'class' => 'icon-info-sign');

        $page['navigation'] = $this->frontendHandler->getTemplateHtml("views/navigation.twig", array("items" => $items));

        /*		SELECTING CLAN INFO TO DISPLAY		*/
        $clanDetails = $this->accountRepository->getClanDetails($clan);

        if (!empty($clanDetails["clantxt"])) {
            if (strip_tags($clanDetails["clantxt"]) != "") {
                $page["clantxt"] = Youtube::integrateYoutubeVideos($clanDetails["clantxt"]);
            }
        }

        if ($clanDetails["clanmods"] == 1) {
            $hist_mods = $this->statRepository->gethisto("clan", $clan, "mod");
            $page['mods'] = $this->frontendHandler->getTemplateHtml("views/pie.twig", array("histogram" => $hist_mods,
                "name" => "$clan mods",
                "id" => "piemods"));
        }

        if ($clanDetails["clanmaps"] == 1) {
            $hist_maps = $this->statRepository->gethisto("clan", $clan, "map");
            $page['maps'] = $this->frontendHandler->getTemplateHtml("views/pie.twig", array("histogram" => $hist_maps,
                "name" => "$clan maps",
                "id" => "piemaps"));
        }


        if ($clanDetails["clancountries"] == 1) {
            $hist_countries = $this->statRepository->gethisto("clan", $clan, "country");
            $page['countries'] = $this->frontendHandler->getTemplateHtml("views/pie.twig", array("histogram" => $hist_countries,
                "name" => "$clan countries",
                "id" => "piecountries"));
        }

        if ($clanDetails["clanhours"] == 1) {
            $hhours = $this->statRepository->gethours("clan", $clan);
            $page['hours'] = $this->frontendHandler->getTemplateHtml("views/bars.twig", array("histogram" => $hhours,
                "name" => "$clan online time per hour",
                "id" => "piehours"));
        }

        if ($clanDetails["clandays"] == 1) {
            $hdays = $this->statRepository->getdays("clan", $clan);
            $page['days'] = $this->frontendHandler->getTemplateHtml("views/bars.twig", array("histogram" => $hdays,
                "name" => "$clan online time per day (Monday to Sunday)",
                "id" => "piedays"));
        }

        if ($clanDetails["clanplayers"] == 1) {
            $players = $this->statRepository->getClanPlayers($clan);
            $page['players'] = $this->frontendHandler->getTemplateHtml("views/playerlist.twig", array("title" => "$clan players",
                "players" => $players));
        }
        $page['title'] = "$clan statistics on Teeworlds";
        $page['clan'] = $clan;

        $this->frontendHandler->renderTemplate("templates/clan.twig", $page);
    }
}