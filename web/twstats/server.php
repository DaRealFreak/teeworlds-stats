<?php

namespace TwStats\Ext;


use TwStats\Core\Frontend\AbstractController;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Ext\Database\AccountRepository;
use TwStats\Ext\Database\StatRepository;
use TwStats\Ext\Facebook\Facebook;

class Server extends AbstractController
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
        // ToDo: argument parsing from url
        $server = 'DDraceNetwork';

        if (empty($server))
            GeneralUtility::redirectToUri(".");

        $name = $this->statRepository->getServerName($server);

        if (!$name) {
            $_SESSION['suggestionsServer'] = $this->statRepository->getSimilarData("server", $server);
            $_SESSION['missingServer'] = true;
            GeneralUtility::redirectToUri(".");
        }
        $server = $name;


        $user = $this->facebook->getFacebookID();

        if ($user) {
            $page['logged'] = true;

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

        $hist_maps = $this->statRepository->gethisto("server", $server, "map");
        $hist_countries = $this->statRepository->gethisto("server", $server, "country");

        $hhours = $this->statRepository->gethours("server", $server);
        $hdays = $this->statRepository->getdays("server", $server);


        $items = array(
            array('text' => 'Game statistics',
                'url' => $this->prettyUrl->buildPrettyUri("general"),
                'class' => 'graphs'),
            array('text' => 'Search',
                'url' => $this->prettyUrl->buildPrettyUri(""),
                'class' => 'gallery'),
            array('text' => 'About',
                'url' => $this->prettyUrl->buildPrettyUri("about"),
                'class' => 'typo')
        );

        $page['navigation'] = $this->frontendHandler->getTemplateHtml("views/navigation.twig", array("items" => $items));

        $players = $this->statRepository->getServerPlayers($server);

        $page['title'] = "$server statistics on Teeworlds";
        $page['server'] = $server;

        $page['players'] = $this->frontendHandler->getTemplateHtml("views/playerlist.twig", array("title" => "Playing tees",
            "players" => $players));

        $page['countries'] = $this->frontendHandler->getTemplateHtml("views/pie.twig", array("histogram" => $hist_countries,
            "name" => "Most playing countries",
            "id" => "piecountries"));
        $page['maps'] = $this->frontendHandler->getTemplateHtml("views/pie.twig", array("histogram" => $hist_maps,
            "name" => "Most played maps	",
            "id" => "piemaps"));
        $page['hours'] = $this->frontendHandler->getTemplateHtml("views/bars.twig", array("histogram" => $hhours,
            "name" => "Online time per hour",
            "id" => "piehours"));
        $page['days'] = $this->frontendHandler->getTemplateHtml("views/bars.twig", array("histogram" => $hdays,
            "name" => "Online time per day (Monday to Sunday)",
            "id" => "piedays"));

        $this->frontendHandler->renderTemplate("server.twig", $page);
    }
}
